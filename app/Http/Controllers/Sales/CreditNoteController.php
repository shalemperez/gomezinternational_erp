<?php

namespace App\Http\Controllers\Sales;

use App\Actions\Sales\ApplyAdvance;
use App\Actions\Sales\IssueCreditNote;
use App\Enums\CreditNoteReason;
use App\Enums\InvoiceStatus;
use App\Exports\TableExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\ApplyAdvanceRequest;
use App\Http\Requests\Sales\StoreCreditNoteRequest;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Lot;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Documents\DocumentPdf;
use App\Services\Ecf\EcfPdfData;
use App\Support\AuditHistory;
use App\Support\Money\Decimal;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class CreditNoteController extends Controller
{
    #[Middleware('permission:sales.credit-notes.view')]
    public function index(Request $request): Response
    {
        $canViewCosts = $request->user()?->can('inventory.stock.view-costs') ?? false;
        $rows = TableQuery::apply($this->filteredQuery($request), $request, ['credit_notes.number', 'credit_notes.ncf', 'credit_notes.modified_ncf'], ['number', 'ncf', 'issue_date', 'total'], 'issue_date', 'desc')
            ->through(fn (CreditNote $creditNote) => [...Arr::except($creditNote->toArray(), $canViewCosts ? [] : ['returned_cost']), 'reason_label' => $creditNote->reason->label()]);

        return Inertia::render('sales/credit-notes/index', [
            'rows' => $rows,
            'filters' => $request->only(['search', 'sort', 'dir', 'per_page', 'client_id', 'reason', 'from', 'to']),
            'reasons' => $this->reasonOptions(),
        ]);
    }

    #[Middleware('permission:sales.credit-notes.create')]
    public function create(Request $request): Response|RedirectResponse
    {
        $invoiceId = $request->integer('invoice');
        $invoice = $invoiceId > 0
            ? Invoice::query()->with(['lines.product:id,sku,name,tracks_lots', 'client:id,business_name', 'currency:id,code'])->whereKey($invoiceId)->first()
            : null;
        if ($invoice === null) {
            return redirect()->route('sales.invoices.index')->with('error', 'Selecciona la factura a la que aplica la nota');
        }
        Gate::authorize('view', $invoice);
        $productIds = $invoice->lines->pluck('product_id')->unique()->values()->all();

        return Inertia::render('sales/credit-notes/form', [
            'invoice' => $this->invoiceProps($invoice, $this->actor($request)),
            'reasons' => $this->reasonOptions(),
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'lots' => Lot::query()->whereIn('product_id', $productIds)->whereHas('product', fn (Builder $query) => $query->where('tracks_lots', true))
                ->orderBy('expires_on')->get(['id', 'code', 'expires_on', 'product_id']),
            'today' => now()->toDateString(),
        ]);
    }

    #[Middleware('permission:sales.credit-notes.create')]
    public function store(StoreCreditNoteRequest $request, IssueCreditNote $issueCreditNote): RedirectResponse
    {
        $invoice = Invoice::query()->whereKey($request->integer('invoice_id'))->firstOrFail();
        $creditNote = $issueCreditNote->handle($request->validated(), $invoice, $this->actor($request));

        return redirect()->route('sales.credit-notes.show', $creditNote)->with('success', "Nota de crédito {$creditNote->number} emitida con ".($creditNote->fiscal_mode === 'ecf' ? 'e-NCF' : 'NCF')." {$creditNote->ncf}");
    }

    #[Middleware('permission:sales.credit-notes.view')]
    public function show(Request $request, CreditNote $creditNote): Response
    {
        Gate::authorize('view', $creditNote);
        $creditNote->loadMissing(['invoice:id,number,ncf', 'client:id,business_name,tax_id', 'salesperson:id,name', 'currency:id,code', 'lines.product:id,sku,name', 'lines.lot:id,code,expires_on']);
        $canViewCosts = $request->user()?->can('inventory.stock.view-costs') ?? false;

        return Inertia::render('sales/credit-notes/show', [
            'creditNote' => [...Arr::except($creditNote->toArray(), $canViewCosts ? [] : ['returned_cost']), 'reason_label' => $creditNote->reason->label(), 'ncf_type_label' => $creditNote->ncf_type->label()],
            'history' => AuditHistory::for($creditNote),
            'openInvoices' => Invoice::query()->visibleTo($this->actor($request))->where('client_id', $creditNote->client_id)->where('currency_id', $creditNote->currency_id)
                ->whereIn('status', [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid])->orderBy('issue_date')
                ->get(['id', 'number', 'ncf', 'issue_date', 'due_date', 'total', 'itbis_total', 'taxable_total', 'balance', 'currency_id']),
            'can' => [
                'apply' => $creditNote->status === 'issued' && Decimal::cmp($creditNote->unapplied_amount, '0') > 0 && $this->actor($request)->can('sales.receipts.create'),
            ],
        ]);
    }

    #[Middleware('permission:sales.receipts.create')]
    public function apply(ApplyAdvanceRequest $request, CreditNote $creditNote, ApplyAdvance $applyAdvance): RedirectResponse
    {
        Gate::authorize('view', $creditNote);
        /** @var list<array{invoice_id: int|string, amount: string}> $applications */
        $applications = $request->validated('applications');
        $applied = Decimal::sum(array_column($applications, 'amount'));
        $creditNote = $applyAdvance->handle($creditNote, $applications, $this->actor($request));

        return redirect()->route('sales.credit-notes.show', $creditNote)->with('success', 'Saldo a favor aplicado: '.DocumentPdf::money($applied, $creditNote->currency->code));
    }

    #[Middleware('permission:sales.credit-notes.view')]
    public function pdf(CreditNote $creditNote, DocumentPdf $pdf): SymfonyResponse
    {
        Gate::authorize('view', $creditNote);
        activity('credit_notes')->performedOn($creditNote)->event('pdf_generated')->log("PDF de {$creditNote->number} generado");

        return $pdf->stream('pdf.sales.credit-note', $this->pdfData($creditNote), $creditNote->number.'.pdf');
    }

    #[Middleware('permission:sales.credit-notes.export')]
    public function export(Request $request): BinaryFileResponse
    {
        $query = TableQuery::applyFilters($this->filteredQuery($request), $request, ['credit_notes.number', 'credit_notes.ncf', 'credit_notes.modified_ncf'], ['number', 'ncf', 'issue_date', 'total'], 'issue_date', 'desc');

        return TableExport::fromQuery($query, 'credit-notes', [
            'Número' => 'number',
            'NCF' => 'ncf',
            'Factura' => 'invoice.number',
            'NCF modificado' => 'modified_ncf',
            'Fecha' => fn (CreditNote $creditNote) => $creditNote->issue_date->format('d/m/Y'),
            'Cliente' => 'client.business_name',
            'Motivo' => fn (CreditNote $creditNote) => $creditNote->reason->label(),
            'Moneda' => 'currency.code',
            'Subtotal' => 'subtotal',
            'ITBIS' => 'itbis_total',
            'Total' => 'total',
        ], $request->string('format', 'xlsx')->toString());
    }

    /** @return array<string, mixed> */
    public function pdfData(CreditNote $creditNote): array
    {
        $creditNote->load('lines.product.unit', 'invoice', 'currency');

        return [
            'creditNote' => $creditNote,
            'lines' => $creditNote->lines->map(fn ($line) => [...$line->toArray(), 'unit' => $line->product?->unit?->name])->values()->all(),
            'ecf' => EcfPdfData::for($creditNote),
            'totals' => [
                'subtotal' => $creditNote->subtotal,
                'discount_total' => $creditNote->discount_total,
                'taxable_total' => $creditNote->taxable_total,
                'itbis_total' => $creditNote->itbis_total,
                'additional_tax_total' => $creditNote->additional_tax_total,
                'total' => $creditNote->total,
                'total_base' => $creditNote->total_base,
            ],
            'currency' => $creditNote->currency->code,
            'copy' => 'Original',
        ];
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /** @return Builder<CreditNote> */
    private function filteredQuery(Request $request): Builder
    {
        $clientId = $request->integer('client_id');
        $reason = $request->string('reason')->toString();
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();

        return CreditNote::query()->visibleTo($this->actor($request))
            ->with(['invoice:id,number', 'client:id,business_name', 'currency:id,code'])
            ->when($clientId > 0, fn (Builder $query) => $query->where('client_id', $clientId))
            ->when($reason !== '', fn (Builder $query) => $query->where('reason', $reason))
            ->when($from !== '', fn (Builder $query) => $query->whereDate('issue_date', '>=', $from))
            ->when($to !== '', fn (Builder $query) => $query->whereDate('issue_date', '<=', $to));
    }

    /** @return array<int, array{value: string, label: string}> */
    private function reasonOptions(): array
    {
        return array_map(fn (CreditNoteReason $reason) => ['value' => $reason->value, 'label' => $reason->label()], CreditNoteReason::cases());
    }

    /** @return array<string, mixed> */
    private function invoiceProps(Invoice $invoice, User $user): array
    {
        if (! $user->can('inventory.stock.view-costs')) {
            $invoice->lines->each->makeHidden('dispatched_cost');
        }

        return [...$invoice->toArray(), 'status_label' => $invoice->status->label()];
    }
}
