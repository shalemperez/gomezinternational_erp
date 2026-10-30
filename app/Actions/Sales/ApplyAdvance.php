<?php

namespace App\Actions\Sales;

use App\Enums\ReceiptStatus;
use App\Exceptions\DocumentStateException;
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
     * @param  list<array{invoice_id: int|string, amount: string}>  $applications
     */
    public function handle(Receipt $receipt, array $applications, User $actor): Receipt
    {
        return DB::transaction(function () use ($receipt, $applications, $actor): Receipt {
            $receipt = Receipt::query()->whereKey($receipt->id)->lockForUpdate()->with('currency')->firstOrFail();
            if ($receipt->status === ReceiptStatus::Cancelled) {
                throw new DocumentStateException('El recibo está anulado');
            }
            if (Decimal::isZero($receipt->unapplied_amount)) {
                throw new DocumentStateException('El recibo no tiene anticipo disponible');
            }

            $invoices = $this->applications->lockAndCheck($applications, (int) $receipt->client_id, (int) $receipt->currency_id);
            $applied = Decimal::sum(array_map(fn (array $application): string => (string) $application['amount'], $applications));
            if (Decimal::cmp($applied, $receipt->unapplied_amount) > 0) {
                throw new DocumentStateException('Las aplicaciones ('.Decimal::money($applied).') superan el anticipo disponible ('.Decimal::money($receipt->unapplied_amount).').');
            }

            $this->applications->record($receipt, $applications, 'advance', $actor, $invoices);
            $receipt->forceFill([
                'applied_total' => Decimal::sum([$receipt->applied_total, $applied]),
                'unapplied_amount' => Decimal::money(Decimal::of($receipt->unapplied_amount)->minus($applied)),
            ])->save();

            activity('receipts')->causedBy($actor)->performedOn($receipt)->event('advance_applied')
                ->withProperties(['amount' => $applied, 'unapplied_amount' => $receipt->unapplied_amount])
                ->log("Anticipo del recibo {$receipt->number} aplicado a facturas");

            $this->events->record('advance_applied', $receipt, [
                'number' => $receipt->number, 'client_id' => $receipt->client_id, 'applications' => $this->applications->eventPayload($applications, $invoices),
                'applied_total' => $receipt->applied_total, 'currency' => $receipt->currency->code, 'exchange_rate' => $receipt->exchange_rate,
            ]);

            return $receipt->fresh();
        });
    }
}
