<?php

namespace App\Actions\Sales;

use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentTerms;
use App\Exceptions\DocumentStateException;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Documents\DocumentCurrency;
use App\Services\Documents\DocumentLines;
use App\Services\Documents\DocumentTotalsCalculator;
use App\Services\Documents\SalespersonResolver;
use App\Support\Documents\LineInput;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class SaveInvoice
{
    public function __construct(private readonly DocumentTotalsCalculator $calculator) {}

    /** @param array<string, mixed> $data validated StoreInvoiceRequest data */
    public function handle(array $data, User $actor, ?Invoice $invoice = null): Invoice
    {
        return DB::transaction(function () use ($data, $actor, $invoice): Invoice {
            if ($invoice !== null && ! $invoice->isDraft()) {
                throw new DocumentStateException('Solo se pueden editar facturas en borrador');
            }

            $issueDate = CarbonImmutable::parse($data['issue_date']);
            $salespersonId = SalespersonResolver::resolve($actor, ($data['salesperson_id'] ?? null) !== null ? (int) $data['salesperson_id'] : null, Invoice::VIEW_ALL_PERMISSION);
            ['exchange_rate' => $rate] = DocumentCurrency::resolve((int) $data['currency_id'], $issueDate);
            $computed = $this->calculator->compute(array_values(array_map(LineInput::fromArray(...), $data['lines'])));
            $creditDays = (int) $data['credit_days'];

            $attributes = [
                'client_id' => (int) $data['client_id'],
                'salesperson_id' => $salespersonId,
                'warehouse_id' => (int) $data['warehouse_id'],
                'currency_id' => (int) $data['currency_id'],
                'exchange_rate' => $rate,
                'issue_date' => $data['issue_date'],
                'due_date' => $issueDate->addDays($data['payment_terms'] === PaymentTerms::Cash->value ? 0 : $creditDays)->toDateString(),
                'payment_terms' => $data['payment_terms'],
                'credit_days' => $creditDays,
                'notes' => $data['notes'] ?? null,
                ...DocumentLines::totals($computed, $rate),
            ];

            if ($invoice === null) {
                $invoice = Invoice::create([
                    ...$attributes,
                    'kind' => InvoiceKind::Sale,
                    'status' => InvoiceStatus::Draft,
                    'balance' => '0.00',
                    'created_by' => $actor->id,
                ]);
            } else {
                $invoice->update($attributes);
            }

            DocumentLines::write($invoice, $computed);

            return $invoice->fresh();
        });
    }
}
