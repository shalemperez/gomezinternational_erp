<?php

namespace App\Actions\Sales;

use App\Enums\CreditNoteReason;
use App\Enums\DocumentKind;
use App\Enums\InvoiceStatus;
use App\Exceptions\DocumentStateException;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\User;
use App\Services\Accounting\AccountingEventRecorder;
use App\Services\Documents\DocumentLines;
use App\Services\Documents\DocumentNumbering;
use App\Services\Documents\DocumentPdf;
use App\Services\Documents\DocumentTotalsCalculator;
use App\Services\Fiscal\FiscalNumberingService;
use App\Services\Fiscal\NcfTypeResolver;
use App\Services\Inventory\InventoryService;
use App\Support\Documents\LineInput;
use App\Support\Inventory\StockLine;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class IssueCreditNote
{
    public function __construct(
        private readonly DocumentTotalsCalculator $calculator,
        private readonly DocumentNumbering $documentNumbering,
        private readonly FiscalNumberingService $numbering,
        private readonly NcfTypeResolver $resolver,
        private readonly InventoryService $inventory,
        private readonly AccountingEventRecorder $events,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(array $data, Invoice $invoice, User $actor): CreditNote
    {
        return DB::transaction(function () use ($data, $invoice, $actor): CreditNote {
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->with(['lines.product', 'currency'])->firstOrFail();
            if (! in_array($invoice->status, [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid, InvoiceStatus::Paid], true)) {
                throw new DocumentStateException("La factura está {$invoice->status->label()} y no admite esta acción");
            }

            $reason = CreditNoteReason::from((string) $data['reason']);
            $rows = array_values($data['lines']);
            $resolved = [];
            foreach ($rows as $row) {
                $invoiceLine = self::nullableInt($row['invoice_line_id'] ?? null) === null ? null : $invoice->lines->firstWhere('id', (int) $row['invoice_line_id']);
                $resolved[] = [
                    'input' => new LineInput(
                        (int) $row['product_id'], (string) $row['quantity'], (string) $row['unit_price'],
                        (string) ($row['discount_percent'] ?? '0'), $row['description'] ?? null, $invoiceLine?->itbis_rate,
                    ),
                    'invoiceLine' => $invoiceLine,
                    'lotId' => self::nullableInt($row['lot_id'] ?? null),
                ];
            }
            $this->assertCapsUnderLock($invoice, $reason, $resolved);

            $computed = $this->calculator->compute(array_map(fn (array $item) => $item['input'], $resolved));
            $totals = DocumentLines::totals($computed, $invoice->exchange_rate);
            if (Decimal::cmp($totals['total'], $invoice->balance) > 0) {
                $currency = $invoice->currency->code;

                throw new DocumentStateException('La nota de crédito ('.DocumentPdf::money($totals['total'], $currency).') supera el saldo pendiente de la factura ('.DocumentPdf::money($invoice->balance, $currency).').');
            }

            $warehouseId = self::nullableInt($data['warehouse_id'] ?? null) ?? $invoice->warehouse_id;
            $issueDate = CarbonImmutable::parse((string) $data['issue_date']);
            $fiscal = $this->numbering->assign($this->resolver->forCreditNote(), $issueDate);
            $note = CreditNote::query()->create([
                'number' => $this->documentNumbering->next(DocumentKind::CreditNote),
                'fiscal_mode' => 'ncf',
                'ncf' => $fiscal->ncf,
                'ncf_type' => $fiscal->type,
                'ncf_expires_on' => $fiscal->expiresOn?->toDateString(),
                'ncf_sequence_id' => $fiscal->sequenceId,
                'invoice_id' => $invoice->id,
                'modified_ncf' => $invoice->ncf,
                'client_id' => $invoice->client_id,
                'salesperson_id' => $invoice->salesperson_id,
                'warehouse_id' => $warehouseId,
                'currency_id' => $invoice->currency_id,
                'exchange_rate' => $invoice->exchange_rate,
                'issue_date' => $issueDate->toDateString(),
                'reason' => $reason,
                ...$totals,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            DocumentLines::write($note, $computed, array_map(fn (array $item) => [
                'invoice_line_id' => $item['invoiceLine']?->id,
                'lot_id' => $item['lotId'],
            ], $resolved));

            $returnedCost = '0';
            foreach ($resolved as $item) {
                $invoiceLine = $item['invoiceLine'];
                if (! $invoiceLine instanceof InvoiceLine) {
                    continue;
                }
                $quantity = Decimal::qty($item['input']->quantity);
                if ($reason === CreditNoteReason::Return) {
                    $unitCost = Decimal::div($invoiceLine->dispatched_cost, $invoiceLine->dispatched_quantity, 4);
                    $movement = $this->inventory->returnStock(
                        new StockLine($invoiceLine->product_id, (int) $warehouseId, $quantity, $unitCost, $item['lotId']),
                        $note, $actor, "NC {$note->number}",
                    );
                    $invoiceLine->forceFill(['returned_quantity' => Decimal::qty(Decimal::of($invoiceLine->returned_quantity)->plus(Decimal::of($quantity)))])->save();
                    $returnedCost = Decimal::sum([$returnedCost, (string) $movement->total_cost]);
                } elseif ($reason === CreditNoteReason::Correction) {
                    $this->inventory->release($invoiceLine->reservations()->whereNull('released_at')->firstOrFail(), $quantity);
                    $invoiceLine->forceFill(['credited_quantity' => Decimal::qty(Decimal::of($invoiceLine->credited_quantity)->plus(Decimal::of($quantity)))])->save();
                }
            }

            $note->forceFill(['status' => 'issued', 'returned_cost' => $returnedCost, 'issued_at' => now()])->save();
            $invoice->forceFill(['credited_amount' => Decimal::sum([$invoice->credited_amount, $totals['total']])])->save();
            $invoice->refreshFinancials();

            activity('credit_notes')->causedBy($actor)->performedOn($note)->event('issued')
                ->withProperties(['number' => $note->number, 'ncf' => $note->ncf, 'reason' => $reason->value, 'total' => $note->total])
                ->log("Nota de crédito {$note->number} emitida con NCF {$note->ncf}");
            activity('invoices')->causedBy($actor)->performedOn($invoice)->event('credit_note_applied')
                ->withProperties(['credit_note_id' => $note->id, 'total' => $note->total])
                ->log("Nota de crédito {$note->number} aplicada a la factura {$invoice->number}");

            $this->events->record('credit_note_issued', $note, [
                'number' => $note->number, 'ncf' => $note->ncf, 'ncf_type' => $note->ncf_type->value, 'modified_ncf' => $note->modified_ncf,
                'invoice_id' => $invoice->id, 'invoice_number' => $invoice->number, 'client_id' => $note->client_id, 'reason' => $reason->value,
                'returns_stock' => $reason === CreditNoteReason::Return, 'issue_date' => $note->issue_date->toDateString(),
                'subtotal' => $note->subtotal, 'discount_total' => $note->discount_total, 'taxable_total' => $note->taxable_total,
                'itbis_total' => $note->itbis_total, 'additional_tax_total' => $note->additional_tax_total, 'total' => $note->total,
                'total_base' => $note->total_base, 'currency' => $invoice->currency->code, 'exchange_rate' => $invoice->exchange_rate,
                'returned_cost' => $returnedCost,
            ], $note->issue_date);

            return $note->fresh();
        });
    }

    /**
     * Re-checks the per-line caps on the locked invoice, summing every row that targets the same invoice line.
     *
     * @param  list<array{input: LineInput, invoiceLine: ?InvoiceLine, lotId: ?int}>  $resolved
     */
    private function assertCapsUnderLock(Invoice $invoice, CreditNoteReason $reason, array $resolved): void
    {
        $totals = [];
        foreach ($resolved as $item) {
            if ($item['invoiceLine'] instanceof InvoiceLine) {
                $id = $item['invoiceLine']->id;
                $totals[$id] = Decimal::sum([$totals[$id] ?? '0', $item['input']->quantity], 4);
            }
        }
        foreach ($totals as $lineId => $quantity) {
            /** @var InvoiceLine $line */
            $line = $invoice->lines->firstWhere('id', $lineId);
            $cap = match ($reason) {
                CreditNoteReason::Return => Decimal::qty(Decimal::of($line->dispatched_quantity)->minus(Decimal::of($line->returned_quantity))),
                CreditNoteReason::Correction => $line->remainingToDispatch(),
                CreditNoteReason::Discount => Decimal::qty($line->quantity),
            };
            if (Decimal::cmp($quantity, $cap) > 0) {
                throw new DocumentStateException("La línea {$line->line_number} supera la cantidad disponible para esta nota ({$cap}).");
            }
        }
    }

    private static function nullableInt(mixed $value): ?int
    {
        return blank($value) ? null : (int) $value;
    }
}
