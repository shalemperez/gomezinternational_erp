<?php

namespace App\Actions\Sales;

use App\Enums\ReceiptStatus;
use App\Exceptions\DocumentStateException;
use App\Models\CreditNote;
use App\Models\Receipt;
use App\Models\User;
use App\Services\Accounting\AccountingEventRecorder;
use App\Services\Sales\ReceiptApplications;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;

class ApplyAdvance
{
    public function __construct(
        private readonly ReceiptApplications $applications,
        private readonly AccountingEventRecorder $events,
    ) {}

    /**
     * Applies a receipt advance or a credit note's client credit to open invoices of the same client and currency.
     *
     * @param  list<array{invoice_id: int|string, amount: string}>  $applications
     */
    public function handle(Receipt|CreditNote $source, array $applications, User $actor): Receipt|CreditNote
    {
        return DB::transaction(function () use ($source, $applications, $actor): Receipt|CreditNote {
            $source = $source::query()->whereKey($source->id)->lockForUpdate()->with('currency')->firstOrFail();
            $isReceipt = $source instanceof Receipt;
            if ($isReceipt && $source->status === ReceiptStatus::Cancelled) {
                throw new DocumentStateException('El recibo está anulado');
            }
            if (! $isReceipt && $source->status !== 'issued') {
                throw new DocumentStateException('La nota de crédito no está emitida');
            }
            if (Decimal::isZero($source->unapplied_amount)) {
                throw new DocumentStateException($isReceipt ? 'El recibo no tiene anticipo disponible' : 'La nota de crédito no tiene saldo a favor disponible');
            }

            $invoices = $this->applications->lockAndCheck($applications, (int) $source->client_id, (int) $source->currency_id);
            $applied = Decimal::sum(array_map(fn (array $application): string => (string) $application['amount'], $applications));
            if (Decimal::cmp($applied, $source->unapplied_amount) > 0) {
                throw new DocumentStateException('Las aplicaciones ('.Decimal::money($applied).') superan el '.($isReceipt ? 'anticipo' : 'saldo a favor').' disponible ('.Decimal::money($source->unapplied_amount).').');
            }

            $this->applications->record($source, $applications, $isReceipt ? 'advance' : 'credit_note', $actor, $invoices);
            $remaining = Decimal::money(Decimal::of($source->unapplied_amount)->minus($applied));
            $source->forceFill($isReceipt
                ? ['applied_total' => Decimal::sum([$source->applied_total, $applied]), 'unapplied_amount' => $remaining]
                : ['unapplied_amount' => $remaining])->save();

            if ($isReceipt) {
                activity('receipts')->causedBy($actor)->performedOn($source)->event('advance_applied')
                    ->withProperties(['amount' => $applied, 'unapplied_amount' => $source->unapplied_amount])
                    ->log("Anticipo del recibo {$source->number} aplicado a facturas");
                $this->events->record('advance_applied', $source, [
                    'number' => $source->number, 'client_id' => $source->client_id, 'applications' => $this->applications->eventPayload($applications, $invoices),
                    'applied_total' => $applied, 'receipt_applied_total' => $source->applied_total, 'currency' => $source->currency->code, 'exchange_rate' => $source->exchange_rate,
                ]);
            } else {
                activity('credit_notes')->causedBy($actor)->performedOn($source)->event('credit_applied')
                    ->withProperties(['amount' => $applied, 'unapplied_amount' => $source->unapplied_amount])
                    ->log("Saldo a favor de la nota {$source->number} aplicado a facturas");
                $this->events->record('credit_note_applied', $source, [
                    'number' => $source->number, 'ncf' => $source->ncf, 'client_id' => $source->client_id, 'applications' => $this->applications->eventPayload($applications, $invoices),
                    'applied_total' => $applied, 'unapplied_amount' => $source->unapplied_amount, 'currency' => $source->currency->code, 'exchange_rate' => $source->exchange_rate,
                ]);
            }

            return $source->fresh();
        });
    }
}
