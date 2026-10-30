<?php

namespace App\Actions\Sales;

use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentTerms;
use App\Exceptions\DocumentStateException;
use App\Models\Invoice;
use App\Models\ProformaLine;
use App\Models\User;
use App\Services\Documents\DocumentCurrency;
use App\Services\Documents\DocumentLines;
use App\Services\Documents\DocumentTotalsCalculator;
use App\Services\Documents\SalespersonResolver;
use App\Support\Documents\LineInput;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

            $extra = $this->proformaLinks($invoice, $data['lines']);
            if ($invoice !== null) {
                $this->guardProformaQuantities($invoice, $data['lines'], $extra);
            }

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

            DocumentLines::write($invoice, $computed, $extra);

            return $invoice->fresh();
        });
    }

    /**
     * Maps each line index to the proforma line it came from: explicit `proforma_line_id` first, then the link already held by a line of the same product.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array{proforma_line_id: int|null}>
     */
    private function proformaLinks(?Invoice $invoice, array $lines): array
    {
        $existingLinks = $invoice?->proforma_id !== null ? $invoice->lines()->whereNotNull('proforma_line_id')->pluck('proforma_line_id', 'product_id')->all() : [];
        $extra = [];
        foreach (array_values($lines) as $index => $row) {
            $extra[$index] = ['proforma_line_id' => isset($row['proforma_line_id']) ? (int) $row['proforma_line_id'] : ($existingLinks[(int) $row['product_id']] ?? null)];
        }

        return $extra;
    }

    /**
     * Refuses draft edits that request more than the proforma line still has pending, excluding this draft's own held quantity.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<int, array{proforma_line_id: int|null}>  $extra
     */
    private function guardProformaQuantities(Invoice $invoice, array $lines, array $extra): void
    {
        $indexesByProformaLine = [];
        foreach ($extra as $index => $link) {
            if ($link['proforma_line_id'] !== null) {
                $indexesByProformaLine[$link['proforma_line_id']][] = $index;
            }
        }

        foreach ($indexesByProformaLine as $proformaLineId => $indexes) {
            $proformaLine = ProformaLine::query()->whereKey($proformaLineId)->lockForUpdate()->firstOrFail();
            $pending = $proformaLine->pendingQuantity($invoice->id);
            $requested = Decimal::sum(array_map(fn (int $index) => (string) array_values($lines)[$index]['quantity'], $indexes), 4);
            if (Decimal::cmp($requested, $pending) > 0) {
                throw ValidationException::withMessages(array_fill_keys(array_map(fn (int $index) => "lines.{$index}.quantity", $indexes), "Cantidad pendiente en la proforma: {$pending}."));
            }
        }
    }
}
