<?php

namespace App\Http\Controllers\Sales;

use App\Actions\Mail\SendDocumentEmail;
use App\Actions\Sales\CancelInvoice;
use App\Actions\Sales\CreateInvoiceFromProforma;
use App\Actions\Sales\IssueInvoice;
use App\Actions\Sales\SaveInvoice;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentTerms;
use App\Exceptions\DocumentStateException;
use App\Exports\TableExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\CancelInvoiceRequest;
use App\Http\Requests\Sales\InvoiceFromProformaRequest;
use App\Http\Requests\Sales\IssueInvoiceRequest;
use App\Http\Requests\Sales\SendDocumentEmailRequest;
use App\Http\Requests\Sales\StoreInvoiceRequest;
use App\Models\CreditNote;
use App\Models\Currency;
use App\Models\Dispatch;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Proforma;
use App\Models\Receipt;
use App\Models\ReceiptApplication;
use App\Models\Salesperson;
use App\Models\SentEmail;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Documents\DocumentPdf;
use App\Services\Settings;
use App\Support\AuditHistory;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class InvoiceController extends Controller
{
    #[Middleware('permission:sales.invoices.view')]
    public function index(Request $request): Response
    {
        $rows = TableQuery::apply($this->filteredQuery($request), $request, ['invoices.number', 'invoices.ncf', 'invoices.client_name'], ['number', 'ncf', 'issue_date', 'due_date', 'total', 'balance', 'status'], 'issue_date', 'desc')
            ->through(fn (Invoice $invoice) => [...$invoice->toArray(), 'status' => $invoice->status->value, 'status_label' => $invoice->status->label(), 'kind_label' => $invoice->kind->label()]);

        return Inertia::render('sales/invoices/index', [
            'rows' => $rows,
            'filters' => $request->only(['search', 'sort', 'dir', 'per_page', 'status', 'kind', 'client_id', 'salesperson_id', 'from', 'to']),
            'statuses' => array_map(fn (InvoiceStatus $status) => ['value' => $status->value, 'label' => $status->label()], InvoiceStatus::cases()),
            'salespeople' => $this->canViewAll($request) ? Salesperson::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']) : [],
            'canViewAll' => $this->canViewAll($request),
        ]);
    }

    #[Middleware('permission:sales.invoices.create')]
    public function create(Request $request): Response
    {
        return Inertia::render('sales/invoices/form', $this->formOptions($request));
    }

    #[Middleware('permission:sales.invoices.create')]
    public function store(StoreInvoiceRequest $request, SaveInvoice $saveInvoice): RedirectResponse
    {
        $invoice = $saveInvoice->handle($request->validated(), $this->actor($request));

        return redirect()->route('sales.invoices.show', $invoice)->with('success', 'Borrador de factura creado');
    }

    #[Middleware('permission:sales.invoices.view')]
    public function show(Request $request, Invoice $invoice): Response
    {
        Gate::authorize('view', $invoice);
        $user = $this->actor($request);

        return Inertia::render('sales/invoices/show', [
            'invoice' => $this->invoiceProps($invoice, $user),
            'history' => AuditHistory::for($invoice),
            'emails' => $this->emailRows($invoice),
            'dispatches' => $this->dispatchRows($invoice),
            'applications' => $this->applicationRows($invoice),
            'can' => [
                'update' => $user->can('sales.invoices.update') && $invoice->isDraft() && $user->can('update', $invoice),
                'delete' => $user->can('sales.invoices.delete') && $invoice->isDraft() && $user->can('delete', $invoice),
                'issue' => $user->can('sales.invoices.issue') && $invoice->isDraft() && $user->can('update', $invoice),
                'cancel' => $user->can('sales.invoices.cancel') && $invoice->isOpen() && $user->can('update', $invoice),
                'send' => $user->can('sales.invoices.send') && $user->can('update', $invoice),
                'creditOverride' => $user->can('sales.invoices.credit-override'),
                'dispatch' => $user->can('inventory.dispatches.create') && in_array($invoice->status, [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid, InvoiceStatus::Paid], true) && $invoice->dispatch_status !== 'complete' && $invoice->kind !== InvoiceKind::Historical,
                'creditNote' => $user->can('sales.credit-notes.create') && ($invoice->isOpen() || $invoice->status === InvoiceStatus::Paid),
                'debitNote' => $user->can('sales.debit-notes.create') && ($invoice->isOpen() || $invoice->status === InvoiceStatus::Paid),
                'receipt' => $user->can('sales.receipts.create') && $invoice->isOpen(),
            ],
        ]);
    }

    #[Middleware('permission:sales.invoices.update')]
    public function edit(Request $request, Invoice $invoice): Response|RedirectResponse
    {
        Gate::authorize('update', $invoice);
        if (! $invoice->isDraft()) {
            return redirect()->route('sales.invoices.show', $invoice)->with('error', 'Solo se pueden editar facturas en borrador');
        }

        return Inertia::render('sales/invoices/form', ['invoice' => $this->invoiceProps($invoice, $this->actor($request)), ...$this->formOptions($request)]);
    }

    #[Middleware('permission:sales.invoices.update')]
    public function update(StoreInvoiceRequest $request, Invoice $invoice, SaveInvoice $saveInvoice): RedirectResponse
    {
        Gate::authorize('update', $invoice);
        if (! $invoice->isDraft()) {
            return back()->with('error', 'Solo se pueden editar facturas en borrador');
        }
        $saved = $saveInvoice->handle($request->validated(), $this->actor($request), $invoice);

        return redirect()->route('sales.invoices.show', $saved)->with('success', 'Borrador de factura actualizado');
    }

    #[Middleware('permission:sales.invoices.delete')]
    public function destroy(Invoice $invoice): RedirectResponse
    {
        Gate::authorize('delete', $invoice);
        if (! $invoice->isDraft()) {
            return back()->with('error', 'Solo se pueden eliminar facturas en borrador');
        }

        try {
            DB::transaction(function () use ($invoice): void {
                $invoice->lines()->get()->each->delete();
                $invoice->delete();
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23503') {
                return back()->with('error', 'No se puede eliminar: el registro está en uso');
            }
            throw $e;
        }

        return redirect()->route('sales.invoices.index')->with('success', 'Borrador de factura eliminado');
    }

    #[Middleware('permission:sales.invoices.issue')]
    public function issue(IssueInvoiceRequest $request, Invoice $invoice, IssueInvoice $issueInvoice): RedirectResponse
    {
        Gate::authorize('update', $invoice);
        $user = $this->actor($request);
        $creditOverride = $request->boolean('credit_override');
        if ($creditOverride && ! $user->can('sales.invoices.credit-override')) {
            abort(403);
        }

        $issued = $issueInvoice->handle($invoice, $user, $creditOverride, $request->string('credit_override_reason')->trim()->toString() ?: null);

        return redirect()->route('sales.invoices.show', $issued)->with('success', "Factura {$issued->number} emitida con ".($issued->fiscal_mode === 'ecf' ? 'e-NCF' : 'NCF')." {$issued->ncf}");
    }

    #[Middleware('permission:sales.invoices.cancel')]
    public function cancel(CancelInvoiceRequest $request, Invoice $invoice, CancelInvoice $cancelInvoice): RedirectResponse
    {
        Gate::authorize('update', $invoice);
        $cancelled = $cancelInvoice->handle($invoice, $this->actor($request), $request->string('reason')->trim()->toString());

        return redirect()->route('sales.invoices.show', $cancelled)->with('success', "Factura {$cancelled->number} anulada");
    }

    #[Middleware('permission:sales.invoices.view')]
    public function pdf(Invoice $invoice, DocumentPdf $pdf): SymfonyResponse
    {
        Gate::authorize('view', $invoice);
        activity('invoices')->performedOn($invoice)->event('pdf_generated')->log('PDF de '.($invoice->number ?? 'borrador').' generado');

        return $pdf->stream('pdf.sales.invoice', $this->pdfData($invoice), ($invoice->number ?? 'borrador-'.$invoice->id).'.pdf');
    }

    #[Middleware('permission:sales.invoices.send')]
    public function send(SendDocumentEmailRequest $request, Invoice $invoice, SendDocumentEmail $sendEmail, DocumentPdf $pdf, Settings $settings): RedirectResponse
    {
        Gate::authorize('update', $invoice);
        if (! $invoice->issued_at) {
            throw new DocumentStateException('Solo se pueden enviar facturas emitidas');
        }
        if ($invoice->status === InvoiceStatus::Cancelled) {
            throw new DocumentStateException('No se puede enviar una factura anulada');
        }
        $user = $this->actor($request);
        $to = array_values($request->validated('to'));
        $cc = array_values($request->validated('cc') ?? []);
        $invoice->loadMissing(['salesperson:id,name', 'currency:id,code']);

        $vars = [
            'cliente' => $invoice->client_name ?? '',
            'numero' => $invoice->number ?? '',
            'empresa' => (string) $settings->get('company.name', ''),
            'total' => DocumentPdf::money($invoice->total, $invoice->currency->code),
            'fecha' => $invoice->issue_date->format('d/m/Y'),
            'vendedor' => $invoice->salesperson->name,
            'usuario' => $user->name,
        ];
        $attachments = [[
            'name' => "{$invoice->number}.pdf",
            'mime' => 'application/pdf',
            'content' => $pdf->binary('pdf.sales.invoice', $this->pdfData($invoice), "{$invoice->number}.pdf"),
        ]];

        $sendEmail->handle($user, $invoice, 'invoice', $to, $cc, $vars, $attachments, $request->validated('body') ?: null, $request->validated('subject') ?: null);
        $invoice->forceFill(['sent_at' => $invoice->sent_at ?? now()])->save();

        return back()->with('success', 'Correo enviado a '.implode(', ', $to));
    }

    #[Middleware('permission:sales.invoices.create')]
    public function fromProforma(InvoiceFromProformaRequest $request, Proforma $proforma, CreateInvoiceFromProforma $createFromProforma): RedirectResponse
    {
        Gate::authorize('update', $proforma);
        $invoice = $createFromProforma->handle($proforma, $request->validated('quantities'), $this->actor($request));

        return redirect()->route('sales.invoices.edit', $invoice)->with('success', "Borrador de factura creado desde {$proforma->number}");
    }

    #[Middleware('permission:sales.invoices.export')]
    public function export(Request $request): BinaryFileResponse
    {
        $query = TableQuery::applyFilters($this->filteredQuery($request), $request, ['invoices.number', 'invoices.ncf', 'invoices.client_name'], ['number', 'ncf', 'issue_date', 'due_date', 'total', 'balance', 'status'], 'issue_date', 'desc');

        return TableExport::fromQuery($query, 'invoices', [
            'Número' => 'number',
            'NCF' => 'ncf',
            'Tipo NCF' => fn (Invoice $invoice) => $invoice->ncf_type?->value,
            'Fecha' => fn (Invoice $invoice) => $invoice->issue_date->format('d/m/Y'),
            'Vencimiento' => fn (Invoice $invoice) => $invoice->due_date->format('d/m/Y'),
            'Cliente' => 'client_name',
            'RNC/Cédula' => 'client_tax_id',
            'Vendedor' => 'salesperson.name',
            'Moneda' => 'currency.code',
            'Subtotal' => 'subtotal',
            'ITBIS' => 'itbis_total',
            'Total' => 'total',
            'Pagado' => 'paid_amount',
            'Saldo' => 'balance',
            'Estado' => fn (Invoice $invoice) => $invoice->status->label(),
        ], $request->string('format', 'xlsx')->toString());
    }

    /** @return array<string, mixed> */
    public function pdfData(Invoice $invoice): array
    {
        $invoice->load('lines.product', 'client', 'salesperson', 'currency');

        return [
            'invoice' => $invoice,
            'lines' => $invoice->lines->map(fn (InvoiceLine $line) => $line->toArray())->values()->all(),
            'totals' => [
                'subtotal' => $invoice->subtotal,
                'discount_total' => $invoice->discount_total,
                'taxable_total' => $invoice->taxable_total,
                'itbis_total' => $invoice->itbis_total,
                'additional_tax_total' => $invoice->additional_tax_total,
                'total' => $invoice->total,
                'total_base' => $invoice->total_base,
            ],
            'currency' => $invoice->currency->code,
            'copy' => 'Original',
        ];
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function canViewAll(Request $request): bool
    {
        return $this->actor($request)->can(Invoice::VIEW_ALL_PERMISSION);
    }

    /** @return Builder<Invoice> */
    private function filteredQuery(Request $request): Builder
    {
        $status = $request->string('status')->toString();
        $kind = $request->string('kind')->toString();
        $clientId = $request->integer('client_id');
        $salespersonId = $request->integer('salesperson_id');
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();

        return Invoice::query()->visibleTo($this->actor($request))
            ->with(['client:id,business_name', 'salesperson:id,name', 'currency:id,code'])
            ->when($status !== '', fn (Builder $query) => $query->where('status', $status))
            ->when($kind !== '', fn (Builder $query) => $query->where('kind', $kind))
            ->when($clientId > 0, fn (Builder $query) => $query->where('client_id', $clientId))
            ->when($salespersonId > 0, fn (Builder $query) => $query->where('salesperson_id', $salespersonId))
            ->when($from !== '', fn (Builder $query) => $query->whereDate('issue_date', '>=', $from))
            ->when($to !== '', fn (Builder $query) => $query->whereDate('issue_date', '<=', $to));
    }

    /** @return array<string, mixed> */
    private function formOptions(Request $request): array
    {
        $user = $this->actor($request);
        $canViewAll = $this->canViewAll($request);

        return [
            'currencies' => Currency::query()->active()->orderBy('code')->get(['id', 'code']),
            'salespeople' => Salesperson::query()->where('is_active', true)
                ->when(! $canViewAll, fn (Builder $query) => $query->whereKey($user->salesperson instanceof Salesperson ? $user->salesperson->id : -1))
                ->orderBy('name')->get(['id', 'name']),
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'defaultWarehouseId' => Warehouse::default()?->id,
            'paymentTerms' => array_map(fn (PaymentTerms $terms) => ['value' => $terms->value, 'label' => $terms->label()], PaymentTerms::cases()),
            'lockSalesperson' => ! $canViewAll,
            'defaultCurrencyId' => Currency::base()->id,
            'today' => now()->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function invoiceProps(Invoice $invoice, User $user): array
    {
        $invoice->loadMissing(['client.currency:id,code', 'salesperson:id,name', 'currency:id,code', 'proforma:id,number', 'canceller:id,name', 'lines.product:id,sku,name']);
        if (! $user->can('inventory.stock.view-costs')) {
            $invoice->lines->each->makeHidden('dispatched_cost');
        }

        return [
            ...$invoice->toArray(),
            'status_label' => $invoice->status->label(),
            'kind_label' => $invoice->kind->label(),
            'ncf_type_label' => $invoice->ncf_type?->label(),
            'client' => [
                'id' => $invoice->client->id,
                'code' => $invoice->client->code,
                'business_name' => $invoice->client->business_name,
                'trade_name' => $invoice->client->trade_name,
                'tax_id' => $invoice->client->tax_id,
                'formatted_tax_id' => $invoice->client->getAttribute('formatted_tax_id'),
                'currency_code' => $invoice->client->currency?->code,
                'credit_days' => $invoice->client->credit_days,
                'email' => $invoice->client->email,
                'salesperson_id' => $invoice->client->salesperson_id,
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function applicationRows(Invoice $invoice): array
    {
        return ReceiptApplication::query()->where('invoice_id', $invoice->id)->with(['receipt:id,number,receipt_date', 'creditNote:id,number'])->orderByDesc('id')->get()
            ->map(fn (ReceiptApplication $application) => [
                'id' => $application->id,
                'receipt_id' => $application->receipt_id,
                'credit_note_id' => $application->credit_note_id,
                'receipt_number' => $application->receipt instanceof Receipt ? $application->receipt->number : ($application->creditNote instanceof CreditNote ? $application->creditNote->number : ''),
                'receipt_date' => ($application->receipt instanceof Receipt ? $application->receipt->receipt_date : $application->applied_on)->toDateString(),
                'amount' => $application->amount,
                'source' => $application->source,
            ])->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function dispatchRows(Invoice $invoice): array
    {
        return $invoice->dispatches()->orderByDesc('id')->get(['id', 'number', 'status', 'dispatch_date'])
            ->map(fn (Dispatch $dispatch) => [
                'id' => $dispatch->id,
                'number' => $dispatch->number,
                'status' => $dispatch->status->value,
                'status_label' => $dispatch->status->label(),
                'dispatch_date' => $dispatch->dispatch_date->toDateString(),
            ])->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function emailRows(Invoice $invoice): array
    {
        return SentEmail::query()->where('mailable_type', Invoice::class)->where('mailable_id', $invoice->id)
            ->with('user:id,name')->latest('id')->get()
            ->map(fn (SentEmail $email) => [
                'id' => $email->id,
                'to' => $email->to,
                'subject' => $email->subject,
                'status' => $email->status,
                'sent_at' => $email->getAttribute('sent_at')?->toIso8601String(),
                'user' => ['name' => $email->user?->name],
            ])->values()->all();
    }
}
