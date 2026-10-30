<?php

namespace App\Actions\Sales;

use App\Enums\DocumentKind;
use App\Enums\InvoiceStatus;
use App\Enums\NcfType;
use App\Enums\PaymentTerms;
use App\Enums\ProformaStatus;
use App\Exceptions\DocumentStateException;
use App\Jobs\SubmitEcfDocument;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Proforma;
use App\Models\ProformaLine;
use App\Models\User;
use App\Services\Accounting\AccountingEventRecorder;
use App\Services\Documents\DocumentCurrency;
use App\Services\Documents\DocumentLines;
use App\Services\Documents\DocumentNumbering;
use App\Services\Documents\DocumentPdf;
use App\Services\Documents\DocumentTotalsCalculator;
use App\Services\Ecf\EcfService;
use App\Services\Fiscal\FiscalNumberingService;
use App\Services\Fiscal\NcfTypeResolver;
use App\Services\Inventory\InventoryService;
use App\Services\Sales\ClientAccount;
use App\Services\Settings;
use App\Support\Documents\ComputedTotals;
use App\Support\Documents\LineInput;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class IssueInvoice
{
    public function __construct(
        private readonly DocumentTotalsCalculator $calculator,
        private readonly DocumentNumbering $documentNumbering,
        private readonly FiscalNumberingService $numbering,
        private readonly NcfTypeResolver $resolver,
        private readonly InventoryService $inventory,
        private readonly AccountingEventRecorder $events,
        private readonly Settings $settings,
        private readonly EcfService $ecf,
    ) {}

    public function handle(Invoice $invoice, User $actor, bool $creditOverride = false, ?string $creditOverrideReason = null): Invoice
    {
        return DB::transaction(function () use ($invoice, $actor, $creditOverride, $creditOverrideReason): Invoice {
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->with(['lines.product', 'client', 'currency'])->firstOrFail();
            if (! $invoice->isDraft()) {
                throw new DocumentStateException("La factura está {$invoice->status->label()} y no admite esta acción");
            }
            if ($invoice->lines->isEmpty()) {
                throw new DocumentStateException('La factura no tiene líneas');
            }
            if (! $invoice->client->is_active) {
                throw new DocumentStateException('El cliente está inactivo');
            }

            $issueDate = CarbonImmutable::today();
            ['exchange_rate' => $rate] = DocumentCurrency::resolve($invoice->currency_id, $issueDate);
            $invoice->exchange_rate = $rate;
            $computed = $this->calculator->compute(array_values($invoice->lines->map(fn (InvoiceLine $line) => new LineInput($line->product_id, $line->quantity, $line->unit_price, $line->discount_percent, $line->description))->all()));
            $totals = DocumentLines::totals($computed, $invoice->exchange_rate);
            $type = $this->resolver->forSale($invoice->client);
            $this->guardConsumoThreshold($type, $totals['total_base'], $invoice->client);
            Client::query()->whereKey($invoice->client_id)->lockForUpdate()->first(); // serialize concurrent issues for the same client
            $this->guardCreditLimit($invoice, $totals['total_base'], $actor, $creditOverride, $creditOverrideReason);

            DocumentLines::write($invoice, $computed, array_map(fn (InvoiceLine $line) => ['proforma_line_id' => $line->proforma_line_id], array_values($invoice->lines->all())));
            foreach ($invoice->lines()->get() as $line) {
                $this->inventory->reserve($line, $line->product_id, (int) $invoice->warehouse_id, $line->quantity);
            }

            $dueDate = $issueDate->addDays($invoice->payment_terms === PaymentTerms::Cash ? 0 : $invoice->credit_days);
            $fiscal = $this->numbering->assign($type, $issueDate);
            $invoice->forceFill([
                ...$totals,
                'exchange_rate' => $rate,
                'number' => $this->documentNumbering->next(DocumentKind::Invoice),
                'ncf' => $fiscal->ncf,
                'ncf_type' => $fiscal->type,
                'ncf_expires_on' => $fiscal->expiresOn?->toDateString(),
                'ncf_sequence_id' => $fiscal->sequenceId,
                'fiscal_mode' => $this->numbering->mode(),
                'client_name' => $invoice->client->business_name,
                'client_tax_id' => $invoice->client->tax_id,
                'client_tax_id_type' => $invoice->client->tax_id_type->value,
                'client_address' => $invoice->client->address,
                'issue_date' => $issueDate->toDateString(),
                'due_date' => $dueDate->toDateString(),
                'status' => InvoiceStatus::Issued,
                'balance' => $totals['total'],
                'issued_at' => now(),
            ])->save();
            $this->invoiceProformaLines($invoice, $actor);
            if ($invoice->fiscal_mode === 'ecf') {
                $ecfDocument = $this->ecf->create($invoice, $fiscal, $actor);
                SubmitEcfDocument::dispatch($ecfDocument)->afterCommit();
            }

            activity('invoices')->causedBy($actor)->performedOn($invoice)->event('issued')
                ->withProperties(['number' => $invoice->number, 'ncf' => $invoice->ncf, 'total' => $invoice->total])
                ->log("Factura {$invoice->number} emitida con ".($invoice->fiscal_mode === 'ecf' ? 'e-NCF' : 'NCF')." {$invoice->ncf}");

            $this->events->record('invoice_issued', $invoice, [
                'number' => $invoice->number, 'ncf' => $invoice->ncf, 'ncf_type' => $invoice->ncf_type?->value, 'fiscal_mode' => $invoice->fiscal_mode, 'kind' => $invoice->kind->value,
                'client_id' => $invoice->client_id, 'client_tax_id' => $invoice->client_tax_id, 'salesperson_id' => $invoice->salesperson_id,
                'issue_date' => $invoice->issue_date->toDateString(), 'due_date' => $invoice->due_date->toDateString(), 'payment_terms' => $invoice->payment_terms->value,
                'subtotal' => $invoice->subtotal, 'discount_total' => $invoice->discount_total, 'taxable_total' => $invoice->taxable_total,
                'itbis_total' => $invoice->itbis_total, 'additional_tax_total' => $invoice->additional_tax_total,
                'additional_taxes' => $this->additionalTaxSummary($computed), 'total' => $invoice->total, 'total_base' => $invoice->total_base,
                'currency' => $invoice->currency->code, 'exchange_rate' => $invoice->exchange_rate,
            ], $invoice->issue_date);

            return $invoice->fresh();
        });
    }

    /** Consumes the proforma quantities now that the invoice is fiscal and marks the proforma invoiced when complete. */
    private function invoiceProformaLines(Invoice $invoice, User $actor): void
    {
        if ($invoice->proforma_id === null) {
            return;
        }
        $proforma = Proforma::query()->whereKey($invoice->proforma_id)->lockForUpdate()->with('lines')->firstOrFail();
        foreach ($invoice->lines()->whereNotNull('proforma_line_id')->get() as $line) {
            $proformaLine = $proforma->lines->firstWhere('id', $line->proforma_line_id);
            if ($proformaLine === null) {
                continue;
            }
            $pending = Decimal::qty(Decimal::of($proformaLine->quantity)->minus(Decimal::of($proformaLine->invoiced_quantity)));
            if (Decimal::cmp($line->quantity, $pending) > 0) {
                throw new DocumentStateException("La proforma {$proforma->number} solo tiene pendiente {$pending} de la línea {$proformaLine->line_number}.");
            }
            $proformaLine->forceFill(['invoiced_quantity' => Decimal::qty(Decimal::of($proformaLine->invoiced_quantity)->plus(Decimal::of($line->quantity)))])->save();
        }
        if ($proforma->lines->every(fn (ProformaLine $line) => Decimal::cmp($line->invoiced_quantity, $line->quantity) >= 0)) {
            $proforma->forceFill(['status' => ProformaStatus::Invoiced, 'invoiced_at' => now()])->save();
            activity('proformas')->causedBy($actor)->performedOn($proforma)->event('invoiced')
                ->withProperties(['invoice_id' => $invoice->id])
                ->log("Proforma {$proforma->number} facturada");
        }
    }

    private function guardConsumoThreshold(NcfType $type, string $totalBase, Client $client): void
    {
        $threshold = Decimal::money((string) $this->settings->get('fiscal.consumo_id_threshold', '250000.00'));
        if ($type === NcfType::B02 && Decimal::cmp($totalBase, $threshold) >= 0 && blank($client->tax_id)) {
            throw new DocumentStateException('Las facturas de consumo desde RD$ '.DocumentPdf::number($threshold).' requieren el RNC o cédula del comprador (Norma General 10-2018).');
        }
    }

    private function guardCreditLimit(Invoice $invoice, string $totalBase, User $actor, bool $override, ?string $reason): void
    {
        $client = $invoice->client;
        if ($invoice->payment_terms === PaymentTerms::Cash || Decimal::isZero($client->credit_limit)) {
            return;
        }

        $limit = $client->credit_limit;
        $outstanding = ClientAccount::outstandingBase($client);
        if (Decimal::cmp(Decimal::sum([$outstanding, $totalBase]), $limit) <= 0) {
            return;
        }

        if (! ($override && $actor->can('sales.invoices.credit-override'))) {
            $available = ClientAccount::availableCredit($client) ?? '0.00';

            throw new DocumentStateException('El cliente supera su límite de crédito (disponible RD$ '.number_format((float) $available, 2).'). Un usuario con permiso puede autorizar el exceso.');
        }

        activity('invoices')->causedBy($actor)->performedOn($invoice)->event('credit_limit_overridden')
            ->withProperties(['limit' => $limit, 'outstanding' => $outstanding, 'total_base' => $totalBase, 'reason' => $reason])
            ->log('Exceso de límite de crédito autorizado');
    }

    /** @return list<array{code: string, amount: string}> */
    private function additionalTaxSummary(ComputedTotals $computed): array
    {
        $amounts = [];
        foreach ($computed->lines as $line) {
            foreach ($line->additionalTaxes as $tax) {
                $amounts[$tax['code']] = Decimal::sum([$amounts[$tax['code']] ?? '0', $tax['amount']]);
            }
        }

        return array_map(fn (string $code, string $amount) => ['code' => $code, 'amount' => $amount], array_keys($amounts), array_values($amounts));
    }
}
