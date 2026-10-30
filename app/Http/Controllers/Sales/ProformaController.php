<?php

namespace App\Http\Controllers\Sales;

use App\Actions\Mail\SendDocumentEmail;
use App\Actions\Sales\DuplicateProforma;
use App\Actions\Sales\SaveProforma;
use App\Actions\Sales\TransitionProforma;
use App\Enums\ProformaStatus;
use App\Exports\TableExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\SendDocumentEmailRequest;
use App\Http\Requests\Sales\StoreProformaRequest;
use App\Http\Requests\Sales\TransitionNoteRequest;
use App\Models\Currency;
use App\Models\Proforma;
use App\Models\ProformaLine;
use App\Models\Salesperson;
use App\Models\SentEmail;
use App\Models\User;
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

class ProformaController extends Controller
{
    #[Middleware('permission:sales.proformas.view')]
    public function index(Request $request): Response
    {
        $rows = TableQuery::apply($this->filteredQuery($request), $request, ['proformas.number'], ['number', 'issue_date', 'valid_until', 'total', 'status'], 'issue_date', 'desc')
            ->through(fn (Proforma $proforma) => [...$proforma->toArray(), 'status' => $proforma->status->value, 'status_label' => $proforma->status->label()]);

        return Inertia::render('sales/proformas/index', [
            'rows' => $rows,
            'filters' => $request->only(['search', 'sort', 'dir', 'per_page', 'status', 'client_id', 'salesperson_id', 'from', 'to']),
            'statuses' => array_map(fn (ProformaStatus $status) => ['value' => $status->value, 'label' => $status->label()], ProformaStatus::cases()),
            'salespeople' => $this->canViewAll($request) ? Salesperson::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']) : [],
            'canViewAll' => $this->canViewAll($request),
        ]);
    }

    #[Middleware('permission:sales.proformas.create')]
    public function create(Request $request): Response
    {
        return Inertia::render('sales/proformas/form', $this->formOptions($request));
    }

    #[Middleware('permission:sales.proformas.create')]
    public function store(StoreProformaRequest $request, SaveProforma $saveProforma): RedirectResponse
    {
        $proforma = $saveProforma->handle($request->validated(), $this->actor($request));

        return redirect()->route('sales.proformas.show', $proforma)->with('success', "Proforma {$proforma->number} creada");
    }

    #[Middleware('permission:sales.proformas.view')]
    public function show(Request $request, Proforma $proforma): Response
    {
        Gate::authorize('view', $proforma);
        $user = $this->actor($request);

        return Inertia::render('sales/proformas/show', [
            'proforma' => $this->proformaProps($proforma),
            'history' => AuditHistory::for($proforma),
            'emails' => $this->emailRows($proforma),
            'can' => [
                'update' => $user->can('sales.proformas.update') && $proforma->isDraft() && $user->can('update', $proforma),
                'delete' => $user->can('sales.proformas.delete') && $proforma->isDraft() && $user->can('delete', $proforma),
                'approve' => $user->can('sales.proformas.approve') && in_array($proforma->status, [ProformaStatus::Draft, ProformaStatus::Sent], true) && $user->can('update', $proforma),
                'send' => $user->can('sales.proformas.send') && $user->can('update', $proforma),
                'invoice' => $user->can('sales.invoices.create') && $proforma->status === ProformaStatus::Approved,
            ],
        ]);
    }

    #[Middleware('permission:sales.proformas.update')]
    public function edit(Request $request, Proforma $proforma): Response|RedirectResponse
    {
        Gate::authorize('update', $proforma);
        if (! $proforma->isDraft()) {
            return redirect()->route('sales.proformas.show', $proforma)->with('error', 'Solo se pueden editar proformas en borrador');
        }

        return Inertia::render('sales/proformas/form', ['proforma' => $this->proformaProps($proforma), ...$this->formOptions($request)]);
    }

    #[Middleware('permission:sales.proformas.update')]
    public function update(StoreProformaRequest $request, Proforma $proforma, SaveProforma $saveProforma): RedirectResponse
    {
        Gate::authorize('update', $proforma);
        $saved = $saveProforma->handle($request->validated(), $this->actor($request), $proforma);

        return redirect()->route('sales.proformas.show', $saved)->with('success', "Proforma {$saved->number} actualizada");
    }

    #[Middleware('permission:sales.proformas.delete')]
    public function destroy(Proforma $proforma): RedirectResponse
    {
        Gate::authorize('delete', $proforma);
        if (! $proforma->isDraft()) {
            return back()->with('error', 'Solo se pueden eliminar proformas en borrador');
        }

        $number = $proforma->number;
        try {
            DB::transaction(function () use ($proforma): void {
                $proforma->lines()->get()->each->delete();
                $proforma->delete();
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23503') {
                return back()->with('error', 'No se puede eliminar: el registro está en uso');
            }
            throw $e;
        }

        return redirect()->route('sales.proformas.index')->with('success', "Proforma {$number} eliminada");
    }

    #[Middleware('permission:sales.proformas.send')]
    public function markSent(Request $request, Proforma $proforma, TransitionProforma $transition): RedirectResponse
    {
        Gate::authorize('update', $proforma);
        $transition->markSent($proforma, $this->actor($request));

        return back()->with('success', "Proforma {$proforma->number} marcada como enviada");
    }

    #[Middleware('permission:sales.proformas.approve')]
    public function approve(TransitionNoteRequest $request, Proforma $proforma, TransitionProforma $transition): RedirectResponse
    {
        Gate::authorize('update', $proforma);
        $transition->approve($proforma, $this->actor($request), $this->note($request));

        return back()->with('success', "Proforma {$proforma->number} aprobada");
    }

    #[Middleware('permission:sales.proformas.approve')]
    public function reject(TransitionNoteRequest $request, Proforma $proforma, TransitionProforma $transition): RedirectResponse
    {
        Gate::authorize('update', $proforma);
        $transition->reject($proforma, $this->actor($request), $this->note($request));

        return back()->with('success', "Proforma {$proforma->number} rechazada");
    }

    #[Middleware('permission:sales.proformas.create')]
    public function duplicate(Request $request, Proforma $proforma, DuplicateProforma $duplicate): RedirectResponse
    {
        Gate::authorize('update', $proforma);
        $copy = $duplicate->handle($proforma, $this->actor($request));

        return redirect()->route('sales.proformas.edit', $copy)->with('success', "Proforma {$copy->number} creada a partir de {$proforma->number}");
    }

    #[Middleware('permission:sales.proformas.view')]
    public function pdf(Proforma $proforma, DocumentPdf $pdf): SymfonyResponse
    {
        Gate::authorize('view', $proforma);
        activity('proformas')->performedOn($proforma)->event('pdf_generated')->log("PDF de {$proforma->number} generado");

        return $pdf->stream('pdf.sales.proforma', $this->pdfData($proforma), "{$proforma->number}.pdf");
    }

    #[Middleware('permission:sales.proformas.send')]
    public function send(SendDocumentEmailRequest $request, Proforma $proforma, SendDocumentEmail $sendEmail, TransitionProforma $transition, DocumentPdf $pdf, Settings $settings): RedirectResponse
    {
        Gate::authorize('update', $proforma);
        $user = $this->actor($request);
        $to = array_values($request->validated('to'));
        $cc = array_values($request->validated('cc') ?? []);
        $proforma->loadMissing(['client', 'salesperson:id,name', 'currency:id,code']);

        $vars = [
            'cliente' => $proforma->client->business_name,
            'numero' => $proforma->number,
            'empresa' => (string) $settings->get('company.name', ''),
            'total' => DocumentPdf::money($proforma->total, $proforma->currency->code),
            'fecha' => $proforma->issue_date->format('d/m/Y'),
            'vendedor' => $proforma->salesperson->name,
            'usuario' => $user->name,
        ];
        $attachments = [[
            'name' => "{$proforma->number}.pdf",
            'mime' => 'application/pdf',
            'content' => $pdf->binary('pdf.sales.proforma', $this->pdfData($proforma), "{$proforma->number}.pdf"),
        ]];

        $sendEmail->handle($user, $proforma, 'proforma', $to, $cc, $vars, $attachments, $request->validated('body') ?: null, $request->validated('subject') ?: null);
        if ($proforma->isDraft()) {
            $transition->markSent($proforma, $user);
        }

        return back()->with('success', 'Correo enviado a '.implode(', ', $to));
    }

    #[Middleware('permission:sales.proformas.export')]
    public function export(Request $request): BinaryFileResponse
    {
        $query = TableQuery::applyFilters($this->filteredQuery($request), $request, ['proformas.number'], ['number', 'issue_date', 'valid_until', 'total', 'status'], 'issue_date', 'desc');

        return TableExport::fromQuery($query, 'proformas', [
            'Número' => 'number',
            'Fecha' => fn (Proforma $proforma) => $proforma->issue_date->format('d/m/Y'),
            'Cliente' => 'client.business_name',
            'Vendedor' => 'salesperson.name',
            'Válida hasta' => fn (Proforma $proforma) => $proforma->valid_until->format('d/m/Y'),
            'Moneda' => 'currency.code',
            'Subtotal' => 'subtotal',
            'ITBIS' => 'itbis_total',
            'Total' => 'total',
            'Estado' => fn (Proforma $proforma) => $proforma->status->label(),
        ], $request->string('format', 'xlsx')->toString());
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function canViewAll(Request $request): bool
    {
        return $this->actor($request)->can(Proforma::VIEW_ALL_PERMISSION);
    }

    private function note(Request $request): ?string
    {
        $note = $request->string('note')->trim()->toString();

        return $note !== '' ? $note : null;
    }

    /** @return Builder<Proforma> */
    private function filteredQuery(Request $request): Builder
    {
        $status = $request->string('status')->toString();
        $clientId = $request->integer('client_id');
        $salespersonId = $request->integer('salesperson_id');
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();

        return Proforma::query()->visibleTo($this->actor($request))
            ->with(['client:id,business_name', 'salesperson:id,name', 'currency:id,code'])
            ->when($status !== '', fn (Builder $query) => $query->where('status', $status))
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
            'lockSalesperson' => ! $canViewAll,
            'defaultCurrencyId' => Currency::base()->id,
            'validityDays' => (int) app(Settings::class)->get('sales.proforma_validity_days', 15),
            'today' => now()->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function proformaProps(Proforma $proforma): array
    {
        $proforma->loadMissing(['client', 'salesperson:id,name', 'currency:id,code', 'approver:id,name', 'lines.product:id,sku,name']);

        return [
            ...$proforma->toArray(),
            'status_label' => $proforma->status->label(),
            'client' => [
                'id' => $proforma->client->id,
                'business_name' => $proforma->client->business_name,
                'trade_name' => $proforma->client->trade_name,
                'formatted_tax_id' => $proforma->client->getAttribute('formatted_tax_id'),
                'address' => $proforma->client->address,
                'email' => $proforma->client->email,
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function emailRows(Proforma $proforma): array
    {
        return SentEmail::query()->where('mailable_type', Proforma::class)->where('mailable_id', $proforma->id)
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

    /** @return array<string, mixed> */
    private function pdfData(Proforma $proforma): array
    {
        $proforma->loadMissing(['client', 'salesperson:id,name', 'currency:id,code', 'lines.product:id,sku,name']);

        return [
            'proforma' => $proforma,
            'lines' => $proforma->lines->map(fn (ProformaLine $line) => $line->toArray())->values()->all(),
            'totals' => [
                'subtotal' => $proforma->subtotal,
                'discount_total' => $proforma->discount_total,
                'taxable_total' => $proforma->taxable_total,
                'itbis_total' => $proforma->itbis_total,
                'additional_tax_total' => $proforma->additional_tax_total,
                'total' => $proforma->total,
                'total_base' => $proforma->total_base,
            ],
            'currency' => $proforma->currency->code,
        ];
    }
}
