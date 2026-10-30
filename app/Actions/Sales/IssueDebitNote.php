<?php

namespace App\Actions\Sales;

use App\Enums\DebitNoteReason;
use App\Enums\DocumentKind;
use App\Enums\InvoiceStatus;
use App\Exceptions\DocumentStateException;
use App\Jobs\SubmitEcfDocument;
use App\Models\DebitNote;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Accounting\AccountingEventRecorder;
use App\Services\Documents\DocumentLines;
use App\Services\Documents\DocumentNumbering;
use App\Services\Documents\DocumentTotalsCalculator;
use App\Services\Ecf\EcfService;
use App\Services\Fiscal\FiscalNumberingService;
use App\Services\Fiscal\NcfTypeResolver;
use App\Support\Documents\LineInput;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class IssueDebitNote
{
    public function __construct(
        private readonly DocumentTotalsCalculator $calculator,
        private readonly DocumentNumbering $documentNumbering,
        private readonly FiscalNumberingService $numbering,
        private readonly NcfTypeResolver $resolver,
        private readonly AccountingEventRecorder $events,
        private readonly EcfService $ecf,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(array $data, Invoice $invoice, User $actor): DebitNote
    {
        return DB::transaction(function () use ($data, $invoice, $actor): DebitNote {
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->with('currency')->firstOrFail();
            if (! in_array($invoice->status, [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid, InvoiceStatus::Paid], true)) {
                throw new DocumentStateException("La factura está {$invoice->status->label()} y no admite esta acción");
            }

            $reason = DebitNoteReason::from((string) $data['reason']);
            $computed = $this->calculator->compute(array_values(array_map(fn (array $row) => new LineInput(
                (int) $row['product_id'], (string) $row['quantity'], (string) $row['unit_price'],
                (string) ($row['discount_percent'] ?? '0'), $row['description'] ?? null,
            ), $data['lines'])));
            $totals = DocumentLines::totals($computed, $invoice->exchange_rate);

            $issueDate = CarbonImmutable::today();
            $fiscal = $this->numbering->assign($this->resolver->forDebitNote(), $issueDate);
            $note = DebitNote::query()->create([
                'number' => $this->documentNumbering->next(DocumentKind::DebitNote),
                'fiscal_mode' => $this->numbering->mode(),
                'ncf' => $fiscal->ncf,
                'ncf_type' => $fiscal->type,
                'ncf_expires_on' => $fiscal->expiresOn?->toDateString(),
                'ncf_sequence_id' => $fiscal->sequenceId,
                'invoice_id' => $invoice->id,
                'modified_ncf' => $invoice->ncf,
                'client_id' => $invoice->client_id,
                'salesperson_id' => $invoice->salesperson_id,
                'currency_id' => $invoice->currency_id,
                'exchange_rate' => $invoice->exchange_rate,
                'issue_date' => $issueDate->toDateString(),
                'reason' => $reason,
                ...$totals,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            DocumentLines::write($note, $computed);

            $note->forceFill(['status' => 'issued', 'issued_at' => now()])->save();
            $invoice->forceFill(['debited_amount' => Decimal::sum([$invoice->debited_amount, $totals['total']])])->save();
            $invoice->refreshFinancials();
            if ($note->fiscal_mode === 'ecf') {
                $ecfDocument = $this->ecf->create($note, $fiscal, $actor);
                SubmitEcfDocument::dispatch($ecfDocument)->afterCommit();
            }

            activity('debit_notes')->causedBy($actor)->performedOn($note)->event('issued')
                ->withProperties(['number' => $note->number, 'ncf' => $note->ncf, 'reason' => $reason->value, 'total' => $note->total])
                ->log("Nota de débito {$note->number} emitida con ".($note->fiscal_mode === 'ecf' ? 'e-NCF' : 'NCF')." {$note->ncf}");
            activity('invoices')->causedBy($actor)->performedOn($invoice)->event('debit_note_applied')
                ->withProperties(['debit_note_id' => $note->id, 'total' => $note->total])
                ->log("Nota de débito {$note->number} aplicada a la factura {$invoice->number}");

            $this->events->record('debit_note_issued', $note, [
                'number' => $note->number, 'ncf' => $note->ncf, 'ncf_type' => $note->ncf_type->value, 'fiscal_mode' => $note->fiscal_mode, 'modified_ncf' => $note->modified_ncf,
                'invoice_id' => $invoice->id, 'invoice_number' => $invoice->number, 'client_id' => $note->client_id, 'reason' => $reason->value,
                'issue_date' => $note->issue_date->toDateString(), 'subtotal' => $note->subtotal, 'discount_total' => $note->discount_total,
                'taxable_total' => $note->taxable_total, 'itbis_total' => $note->itbis_total, 'additional_tax_total' => $note->additional_tax_total,
                'total' => $note->total, 'total_base' => $note->total_base, 'currency' => $invoice->currency->code, 'exchange_rate' => $invoice->exchange_rate,
            ], $note->issue_date);

            return $note->fresh();
        });
    }
}
