<?php

namespace App\Services\Sales;

use App\Exceptions\DocumentStateException;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Receipt;
use App\Models\ReceiptApplication;
use App\Models\User;
use App\Support\Money\Decimal;
use Illuminate\Support\Collection;

class ReceiptApplications
{
    /**
     * Locks the applied invoices and re-checks them against the receipt inside the caller's transaction.
     * Amounts are summed per invoice, so repeated rows cannot exceed the balance.
     *
     * @param  list<array{invoice_id: int|string, amount: string}>  $applications
     * @return Collection<int, Invoice> keyed by id
     */
    public function lockAndCheck(array $applications, int $clientId, int $currencyId): Collection
    {
        $ids = array_values(array_unique(array_map(fn (array $application): int => (int) $application['invoice_id'], $applications)));
        $invoices = Invoice::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $perInvoice = [];
        foreach ($applications as $application) {
            $invoiceId = (int) $application['invoice_id'];
            $perInvoice[$invoiceId] = Decimal::sum([$perInvoice[$invoiceId] ?? '0', (string) $application['amount']]);
        }

        foreach ($perInvoice as $invoiceId => $amount) {
            $invoice = $invoices->get($invoiceId) ?? throw new DocumentStateException('La factura ya no existe');
            if (! $invoice->isOpen()) {
                throw new DocumentStateException("La factura {$invoice->number} no está pendiente de cobro.");
            }
            if ((int) $invoice->client_id !== $clientId) {
                throw new DocumentStateException("La factura {$invoice->number} es de otro cliente.");
            }
            if ((int) $invoice->currency_id !== $currencyId) {
                throw new DocumentStateException("La factura {$invoice->number} está en otra moneda.");
            }
            if (Decimal::cmp($amount, $invoice->balance) > 0) {
                throw new DocumentStateException("La aplicación supera el saldo de la factura {$invoice->number} ({$invoice->balance}).");
            }
        }

        return $invoices;
    }

    /**
     * Stores the applications, reduces each invoice's balance and records the payment on the invoice.
     * A receipt or a credit note is the source; a credit note application has no receipt and is dated today.
     *
     * @param  list<array{invoice_id: int|string, amount: string}>  $applications
     * @param  Collection<int, Invoice>  $invoices  as returned by lockAndCheck()
     */
    public function record(Receipt|CreditNote $source, array $applications, string $sourceType, User $actor, Collection $invoices): void
    {
        foreach ($applications as $application) {
            $invoiceId = (int) $application['invoice_id'];
            $invoice = $invoices->get($invoiceId) ?? throw new DocumentStateException('La factura ya no existe');
            $amount = (string) $application['amount'];

            ReceiptApplication::query()->create([
                'receipt_id' => $source instanceof Receipt ? $source->id : null,
                'credit_note_id' => $source instanceof CreditNote ? $source->id : null,
                'invoice_id' => $invoiceId,
                'amount' => $amount,
                'source' => $sourceType,
                'applied_on' => $sourceType === 'receipt' && $source instanceof Receipt ? $source->receipt_date->toDateString() : today()->toDateString(),
                'created_by' => $actor->id,
            ]);
            $invoice->forceFill(['paid_amount' => Decimal::sum([$invoice->paid_amount, $amount])])->save();
            $invoice->refreshFinancials();

            activity('invoices')->causedBy($actor)->performedOn($invoice)->event('payment_applied')
                ->withProperties([
                    'receipt_id' => $source instanceof Receipt ? $source->id : null,
                    'credit_note_id' => $source instanceof CreditNote ? $source->id : null,
                    'number' => $source->number, 'amount' => $amount, 'source' => $sourceType,
                ])
                ->log($sourceType === 'credit_note'
                    ? "Saldo a favor de la nota {$source->number} aplicado a la factura {$invoice->number}"
                    : "Cobro {$source->number} aplicado a la factura {$invoice->number}");
        }
    }

    /**
     * @param  list<array{invoice_id: int|string, amount: string}>  $applications
     * @param  Collection<int, Invoice>  $invoices
     * @return list<array{invoice_id: int, invoice_number: string|null, ncf: string|null, amount: string}>
     */
    public function eventPayload(array $applications, Collection $invoices): array
    {
        return array_map(function (array $application) use ($invoices): array {
            $invoiceId = (int) $application['invoice_id'];
            $invoice = $invoices->get($invoiceId);

            return [
                'invoice_id' => $invoiceId, 'invoice_number' => $invoice?->number, 'ncf' => $invoice?->ncf, 'amount' => (string) $application['amount'],
            ];
        }, $applications);
    }
}
