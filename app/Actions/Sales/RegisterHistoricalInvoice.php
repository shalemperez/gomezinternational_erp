<?php

namespace App\Actions\Sales;

use App\Enums\DocumentKind;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Enums\NcfType;
use App\Enums\PaymentTerms;
use App\Exceptions\DocumentStateException;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Accounting\AccountingEventRecorder;
use App\Services\Documents\DocumentCurrency;
use App\Services\Documents\DocumentNumbering;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class RegisterHistoricalInvoice
{
    public function __construct(
        private readonly DocumentNumbering $documentNumbering,
        private readonly AccountingEventRecorder $events,
    ) {}

    /** @param array<string, mixed> $data validated historical invoice data */
    public function handle(array $data, User $actor): Invoice
    {
        return DB::transaction(function () use ($data, $actor): Invoice {
            $client = Client::query()->whereKey((int) $data['client_id'])->firstOrFail();
            $issueDate = CarbonImmutable::parse($data['issue_date']);
            $salespersonId = $data['salesperson_id'] ?? $client->salesperson_id
                ?? throw new DocumentStateException('El cliente no tiene vendedor asignado; indica el vendedor.');
            ['currency' => $currency, 'exchange_rate' => $rate] = DocumentCurrency::resolve((int) $data['currency_id'], $issueDate);

            $taxableTotal = Decimal::money((string) $data['taxable_total']);
            $itbisTotal = Decimal::money((string) $data['itbis_total']);
            $total = Decimal::money((string) $data['total']);
            $additionalTaxTotal = Decimal::money(Decimal::of($total)->minus(Decimal::sum([$taxableTotal, $itbisTotal])));
            if (Decimal::cmp($additionalTaxTotal, '0') < 0) {
                throw new DocumentStateException('El total no puede ser menor que la base imponible más el ITBIS.');
            }
            $paidAmount = Decimal::money((string) ($data['paid_amount'] ?? '0'));
            $ncf = (string) $data['ncf'];
            $number = $this->documentNumbering->next(DocumentKind::Invoice);

            $invoice = Invoice::query()->create([
                'number' => $number,
                'kind' => InvoiceKind::Historical,
                'status' => InvoiceStatus::Issued,
                'fiscal_mode' => 'external',
                'ncf' => $ncf,
                'ncf_type' => NcfType::from(substr($ncf, 0, 3)),
                'client_id' => $client->id,
                'client_name' => $client->business_name,
                'client_tax_id' => $client->tax_id,
                'client_tax_id_type' => $client->tax_id_type->value,
                'client_address' => $client->address,
                'salesperson_id' => (int) $salespersonId,
                'warehouse_id' => null,
                'currency_id' => $currency->id,
                'exchange_rate' => $rate,
                'issue_date' => $issueDate->toDateString(),
                'due_date' => filled($data['due_date'] ?? null)
                    ? (string) $data['due_date']
                    : $issueDate->addDays($client->credit_days)->toDateString(),
                'payment_terms' => PaymentTerms::Credit,
                'credit_days' => $client->credit_days,
                'subtotal' => $taxableTotal,
                'discount_total' => '0.00',
                'taxable_total' => $taxableTotal,
                'itbis_total' => $itbisTotal,
                'additional_tax_total' => $additionalTaxTotal,
                'total' => $total,
                'total_base' => Decimal::mul($total, $rate, Decimal::MONEY),
                'paid_amount' => $paidAmount,
                'credited_amount' => '0.00',
                'debited_amount' => '0.00',
                'balance' => $total,
                'notes' => $data['notes'] ?? null,
                'issued_at' => $issueDate,
                'created_by' => $actor->id,
            ]);
            $invoice->refreshFinancials();
            $invoice = $invoice->fresh() ?? $invoice;

            activity('invoices')->causedBy($actor)->performedOn($invoice)->event('registered_historical')
                ->withProperties(['number' => $number, 'ncf' => $ncf, 'total' => $total, 'paid_amount' => $paidAmount])
                ->log("Factura histórica {$ncf} registrada");

            $this->events->record('historical_invoice_registered', $invoice, [
                'number' => $invoice->number, 'ncf' => $invoice->ncf, 'ncf_type' => $invoice->ncf_type?->value, 'client_id' => $invoice->client_id,
                'salesperson_id' => $invoice->salesperson_id, 'issue_date' => $invoice->issue_date->toDateString(), 'due_date' => $invoice->due_date->toDateString(),
                'taxable_total' => $invoice->taxable_total, 'itbis_total' => $invoice->itbis_total, 'additional_tax_total' => $invoice->additional_tax_total,
                'total' => $invoice->total, 'total_base' => $invoice->total_base, 'paid_amount' => $invoice->paid_amount, 'balance' => $invoice->balance,
                'currency' => $currency->code, 'exchange_rate' => $rate,
            ], $issueDate);

            return $invoice;
        });
    }
}
