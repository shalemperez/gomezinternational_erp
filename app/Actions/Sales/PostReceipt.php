<?php

namespace App\Actions\Sales;

use App\Enums\DocumentKind;
use App\Enums\ReceiptStatus;
use App\Exceptions\DocumentStateException;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Receipt;
use App\Models\User;
use App\Services\Accounting\AccountingEventRecorder;
use App\Services\Documents\DocumentCurrency;
use App\Services\Documents\DocumentNumbering;
use App\Services\Sales\ReceiptApplications;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class PostReceipt
{
    public function __construct(
        private readonly DocumentNumbering $documentNumbering,
        private readonly ReceiptApplications $applications,
        private readonly AccountingEventRecorder $events,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(array $data, User $actor): Receipt
    {
        return DB::transaction(function () use ($data, $actor): Receipt {
            /** @var list<array{invoice_id: int|string, amount: string}> $applications */
            $applications = $data['applications'];
            $client = Client::query()->whereKey((int) $data['client_id'])->firstOrFail();
            $resolved = DocumentCurrency::resolve((int) $data['currency_id'], CarbonImmutable::parse((string) $data['receipt_date']));

            $amountReceived = Decimal::money((string) $data['amount_received']);
            $retentionItbis = Decimal::money((string) $data['retention_itbis']);
            $retentionIsr = Decimal::money((string) $data['retention_isr']);
            $credit = Decimal::sum([$amountReceived, $retentionItbis, $retentionIsr]);

            $invoices = $this->applications->lockAndCheck($applications, $client->id, $resolved['currency']->id);
            $applied = Decimal::sum(array_map(fn (array $application): string => (string) $application['amount'], $applications));
            if (Decimal::cmp($applied, $credit) > 0) {
                throw new DocumentStateException('Las aplicaciones ('.Decimal::money($applied).') superan el monto del recibo ('.Decimal::money($credit).').');
            }
            if (Decimal::cmp($retentionItbis, Decimal::sum($invoices->map(fn (Invoice $invoice) => $invoice->itbis_total))) > 0) {
                throw new DocumentStateException('La retención de ITBIS supera el ITBIS de las facturas aplicadas.');
            }
            if (Decimal::cmp($retentionIsr, Decimal::sum($invoices->map(fn (Invoice $invoice) => $invoice->taxable_total))) > 0) {
                throw new DocumentStateException('La retención de ISR supera la base de las facturas aplicadas.');
            }

            $receipt = Receipt::query()->create([
                'number' => $this->documentNumbering->next(DocumentKind::Receipt),
                'status' => ReceiptStatus::Posted,
                'client_id' => $client->id,
                'salesperson_id' => $client->salesperson_id,
                'currency_id' => $resolved['currency']->id,
                'exchange_rate' => $resolved['exchange_rate'],
                'receipt_date' => $data['receipt_date'],
                'payment_method' => $data['payment_method'],
                'financial_account_id' => $data['financial_account_id'],
                'reference' => $data['reference'] ?? null,
                'amount_received' => $amountReceived,
                'retention_itbis' => $retentionItbis,
                'retention_isr' => $retentionIsr,
                'applied_total' => $applied,
                'unapplied_amount' => Decimal::money(Decimal::of($credit)->minus($applied)),
                'notes' => $data['notes'] ?? null,
                'posted_at' => now(),
                'created_by' => $actor->id,
            ]);

            $this->applications->record($receipt, $applications, 'receipt', $actor, $invoices);

            activity('receipts')->causedBy($actor)->performedOn($receipt)->event('posted')
                ->withProperties(['applied_total' => $applied, 'unapplied_amount' => $receipt->unapplied_amount])
                ->log("Recibo {$receipt->number} registrado");

            $this->events->record('receipt_posted', $receipt, [
                'number' => $receipt->number, 'client_id' => $client->id, 'receipt_date' => $receipt->receipt_date->toDateString(),
                'payment_method' => $receipt->payment_method->value, 'financial_account_id' => $receipt->financial_account_id, 'reference' => $receipt->reference,
                'amount_received' => $receipt->amount_received, 'retention_itbis' => $receipt->retention_itbis, 'retention_isr' => $receipt->retention_isr,
                'applications' => $this->applications->eventPayload($applications, $invoices), 'applied_total' => $receipt->applied_total,
                'unapplied_amount' => $receipt->unapplied_amount, 'currency' => $resolved['currency']->code, 'exchange_rate' => $receipt->exchange_rate,
            ], $receipt->receipt_date);

            return $receipt->fresh();
        });
    }
}
