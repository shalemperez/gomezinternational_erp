<?php

namespace App\Http\Controllers\Sales;

use App\Actions\Sales\IssueDebitNote;
use App\Enums\DebitNoteReason;
use App\Enums\InvoiceStatus;
use App\Exports\TableExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreDebitNoteRequest;
use App\Models\DebitNote;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Documents\DocumentPdf;
use App\Support\AuditHistory;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class DebitNoteController extends Controller
{
    #[Middleware('permission:sales.debit-notes.view')]
    public function index(Request $request): Response
    {
        $rows = TableQuery::apply($this->filteredQuery($request), $request, ['debit_notes.number', 'debit_notes.ncf', 'debit_notes.modified_ncf'], ['number', 'ncf', 'issue_date', 'total'], 'issue_date', 'desc')
            ->through(fn (DebitNote $debitNote) => [...$debitNote->toArray(), 'reason_label' => $debitNote->reason->label()]);

        return Inertia::render('sales/debit-notes/index', [
            'rows' => $rows,
            'filters' => $request->only(['search', 'sort', 'dir', 'per_page', 'client_id', 'reason', 'from', 'to']),
            'reasons' => $this->reasonOptions(),
        ]);
    }

    #[Middleware('permission:sales.debit-notes.create')]
    public function create(Request $request): Response|RedirectResponse
    {
        $invoiceId = $request->integer('invoice');
        $invoice = $invoiceId > 0
            ? Invoice::query()->with(['client:id,business_name', 'currency:id,code'])->whereKey($invoiceId)->first()
            : null;
        if ($invoice === null) {
            return redirect()->route('sales.invoices.index')->with('error', 'Selecciona la factura a la que aplica la nota');
        }
        Gate::authorize('view', $invoice);
        $isDebitable = in_array($invoice->status, [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid, InvoiceStatus::Paid], true);

        return Inertia::render('sales/debit-notes/form', [
            'invoice' => $isDebitable ? [...$invoice->toArray(), 'status_label' => $invoice->status->label()] : null,
            'reasons' => $this->reasonOptions(),
            'today' => now()->toDateString(),
        ]);
    }

    #[Middleware('permission:sales.debit-notes.create')]
    public function store(StoreDebitNoteRequest $request, IssueDebitNote $issueDebitNote): RedirectResponse
    {
        $invoice = Invoice::query()->whereKey($request->integer('invoice_id'))->firstOrFail();
        $debitNote = $issueDebitNote->handle($request->validated(), $invoice, $this->actor($request));

        return redirect()->route('sales.debit-notes.show', $debitNote)->with('success', "Nota de débito {$debitNote->number} emitida con NCF {$debitNote->ncf}");
    }

    #[Middleware('permission:sales.debit-notes.view')]
    public function show(DebitNote $debitNote): Response
    {
        Gate::authorize('view', $debitNote);
        $debitNote->loadMissing(['invoice:id,number,ncf', 'client:id,business_name,tax_id', 'salesperson:id,name', 'currency:id,code', 'lines.product:id,sku,name']);

        return Inertia::render('sales/debit-notes/show', [
            'debitNote' => [...$debitNote->toArray(), 'reason_label' => $debitNote->reason->label(), 'ncf_type_label' => $debitNote->ncf_type->label()],
            'history' => AuditHistory::for($debitNote),
        ]);
    }

    #[Middleware('permission:sales.debit-notes.view')]
    public function pdf(DebitNote $debitNote, DocumentPdf $pdf): SymfonyResponse
    {
        Gate::authorize('view', $debitNote);
        activity('debit_notes')->performedOn($debitNote)->event('pdf_generated')->log("PDF de {$debitNote->number} generado");

        return $pdf->stream('pdf.sales.debit-note', $this->pdfData($debitNote), $debitNote->number.'.pdf');
    }

    #[Middleware('permission:sales.debit-notes.export')]
    public function export(Request $request): BinaryFileResponse
    {
        $query = TableQuery::applyFilters($this->filteredQuery($request), $request, ['debit_notes.number', 'debit_notes.ncf', 'debit_notes.modified_ncf'], ['number', 'ncf', 'issue_date', 'total'], 'issue_date', 'desc');

        return TableExport::fromQuery($query, 'debit-notes', [
            'Número' => 'number',
            'NCF' => 'ncf',
            'Factura' => 'invoice.number',
            'NCF modificado' => 'modified_ncf',
            'Fecha' => fn (DebitNote $debitNote) => $debitNote->issue_date->format('d/m/Y'),
            'Cliente' => 'client.business_name',
            'Motivo' => fn (DebitNote $debitNote) => $debitNote->reason->label(),
            'Moneda' => 'currency.code',
            'Subtotal' => 'subtotal',
            'ITBIS' => 'itbis_total',
            'Total' => 'total',
        ], $request->string('format', 'xlsx')->toString());
    }

    /** @return array<string, mixed> */
    public function pdfData(DebitNote $debitNote): array
    {
        $debitNote->load('lines', 'invoice', 'currency');

        return [
            'debitNote' => $debitNote,
            'lines' => $debitNote->lines->map(fn ($line) => $line->toArray())->values()->all(),
            'totals' => [
                'subtotal' => $debitNote->subtotal,
                'discount_total' => $debitNote->discount_total,
                'taxable_total' => $debitNote->taxable_total,
                'itbis_total' => $debitNote->itbis_total,
                'additional_tax_total' => $debitNote->additional_tax_total,
                'total' => $debitNote->total,
                'total_base' => $debitNote->total_base,
            ],
            'currency' => $debitNote->currency->code,
            'copy' => 'Original',
        ];
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /** @return Builder<DebitNote> */
    private function filteredQuery(Request $request): Builder
    {
        $clientId = $request->integer('client_id');
        $reason = $request->string('reason')->toString();
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();

        return DebitNote::query()->visibleTo($this->actor($request))
            ->with(['invoice:id,number', 'client:id,business_name', 'currency:id,code'])
            ->when($clientId > 0, fn (Builder $query) => $query->where('client_id', $clientId))
            ->when($reason !== '', fn (Builder $query) => $query->where('reason', $reason))
            ->when($from !== '', fn (Builder $query) => $query->whereDate('issue_date', '>=', $from))
            ->when($to !== '', fn (Builder $query) => $query->whereDate('issue_date', '<=', $to));
    }

    /** @return array<int, array{value: string, label: string}> */
    private function reasonOptions(): array
    {
        return array_map(fn (DebitNoteReason $reason) => ['value' => $reason->value, 'label' => $reason->label()], DebitNoteReason::cases());
    }
}
