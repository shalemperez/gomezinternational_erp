<?php

namespace App\Http\Controllers\Fiscal;

use App\Enums\EcfStatus;
use App\Exports\TableExport;
use App\Http\Controllers\Controller;
use App\Jobs\PollEcfStatus;
use App\Jobs\SubmitEcfDocument;
use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\EcfDocument;
use App\Models\EcfTransmission;
use App\Models\Invoice;
use App\Models\User;
use App\Support\AuditHistory;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class EcfDocumentController extends Controller
{
    /** @var list<string> */
    private const SEARCHABLE = ['ecf_documents.encf', 'ecf_documents.track_id'];

    /** @var list<string> */
    private const SORTABLE = ['encf', 'status', 'created_at', 'signed_at', 'attempts'];

    #[Middleware('permission:fiscal.ecf.view')]
    public function index(Request $request): Response
    {
        $rows = TableQuery::apply($this->filteredQuery($request), $request, self::SEARCHABLE, self::SORTABLE, 'created_at', 'desc')
            ->through(fn (EcfDocument $ecfDocument) => $this->indexRow($ecfDocument));

        return Inertia::render('fiscal/ecf-documents/index', [
            'rows' => $rows,
            'filters' => $request->only(['search', 'sort', 'dir', 'per_page', 'status', 'flow', 'environment', 'from', 'to']),
            'statuses' => array_map(fn (EcfStatus $status) => ['value' => $status->value, 'label' => $status->label()], EcfStatus::cases()),
        ]);
    }

    #[Middleware('permission:fiscal.ecf.view')]
    public function show(Request $request, EcfDocument $ecfDocument): Response
    {
        Gate::authorize('view', $ecfDocument->document);
        $ecfDocument->loadMissing(['document.client:id,business_name', 'document.currency:id,code', 'transmissions']);

        return Inertia::render('fiscal/ecf-documents/show', [
            'ecfDocument' => [
                ...$ecfDocument->summary(),
                'commercial_status' => $ecfDocument->commercial_status,
                'commercial_reason' => $ecfDocument->commercial_reason,
                'commercial_at' => $ecfDocument->commercial_at?->toIso8601String(),
                'dgii_code' => $ecfDocument->dgii_code,
                'environment_label' => $ecfDocument->environment->label(),
                'xml_url' => $ecfDocument->xml_path !== null ? route('fiscal.ecf-documents.xml', $ecfDocument) : null,
                'rfce_url' => $ecfDocument->rfce_xml_path !== null ? route('fiscal.ecf-documents.xml', [$ecfDocument, 'file' => 'rfce']) : null,
                'document' => $this->documentSummary($ecfDocument->document),
            ],
            'transmissions' => $ecfDocument->transmissions->map(fn (EcfTransmission $transmission) => [
                'id' => $transmission->id,
                'kind' => $transmission->kind,
                'http_status' => $transmission->http_status,
                'succeeded' => $transmission->succeeded,
                'error' => $transmission->error,
                'created_at' => $transmission->created_at->toIso8601String(),
                'url' => route('fiscal.ecf-documents.transmission', [$ecfDocument, $transmission]),
            ])->values()->all(),
            'history' => AuditHistory::for($ecfDocument),
            'can' => ['manage' => $this->actor($request)->can('fiscal.ecf.manage')],
        ]);
    }

    #[Middleware('permission:fiscal.ecf.manage')]
    public function retry(EcfDocument $ecfDocument): RedirectResponse
    {
        Gate::authorize('view', $ecfDocument->document);
        if (! in_array($ecfDocument->status, [EcfStatus::Pending, EcfStatus::Signed], true)) {
            return back()->with('error', 'Solo se pueden reenviar e-CF pendientes de envío');
        }

        SubmitEcfDocument::dispatch($ecfDocument);

        return back()->with('success', 'Reenvío en cola: el estado se actualizará en breve');
    }

    #[Middleware('permission:fiscal.ecf.manage')]
    public function poll(EcfDocument $ecfDocument): RedirectResponse
    {
        Gate::authorize('view', $ecfDocument->document);
        if (! in_array($ecfDocument->status, [EcfStatus::Sent, EcfStatus::InProcess], true)) {
            return back()->with('error', 'Solo se puede consultar el estado de e-CF enviados');
        }

        PollEcfStatus::dispatch($ecfDocument);

        return back()->with('success', 'Consulta en cola: el estado se actualizará en breve');
    }

    #[Middleware('permission:fiscal.ecf.view')]
    public function xml(Request $request, EcfDocument $ecfDocument): SymfonyResponse
    {
        Gate::authorize('view', $ecfDocument->document);
        $path = $request->string('file', 'ecf')->toString() === 'rfce' ? $ecfDocument->rfce_xml_path : $ecfDocument->xml_path;
        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, basename($path));
    }

    #[Middleware('permission:fiscal.ecf.view')]
    public function transmission(EcfDocument $ecfDocument, EcfTransmission $transmission): JsonResponse
    {
        Gate::authorize('view', $ecfDocument->document);
        abort_unless($transmission->ecf_document_id === $ecfDocument->id, 404);

        return response()->json([
            'response' => $transmission->response,
            'http_status' => $transmission->http_status,
            'error' => $transmission->error,
            'created_at' => $transmission->created_at->toIso8601String(),
        ]);
    }

    #[Middleware('permission:fiscal.ecf.export')]
    public function export(Request $request): BinaryFileResponse
    {
        $query = TableQuery::applyFilters($this->filteredQuery($request), $request, self::SEARCHABLE, self::SORTABLE, 'created_at', 'desc');

        return TableExport::fromQuery($query, 'ecf-documents', [
            'e-NCF' => 'encf',
            'Tipo' => fn (EcfDocument $ecfDocument) => $ecfDocument->ncf_type->label(),
            'Documento' => fn (EcfDocument $ecfDocument) => $this->documentSummary($ecfDocument->document)['number'],
            'Cliente' => fn (EcfDocument $ecfDocument) => $this->documentSummary($ecfDocument->document)['client_name'],
            'Total' => fn (EcfDocument $ecfDocument) => $this->documentSummary($ecfDocument->document)['total'],
            'Flujo' => fn (EcfDocument $ecfDocument) => $ecfDocument->flow === 'rfce' ? 'Resumen (RFCE)' : 'e-CF',
            'Estado' => fn (EcfDocument $ecfDocument) => $ecfDocument->status->label(),
            'TrackId' => 'track_id',
            'Fecha firma' => fn (EcfDocument $ecfDocument) => $ecfDocument->signed_at?->format('d/m/Y H:i'),
            'Intentos' => 'attempts',
        ], $request->string('format', 'xlsx')->toString());
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /** @return Builder<EcfDocument> */
    private function filteredQuery(Request $request): Builder
    {
        $user = $this->actor($request);
        $status = $request->string('status')->toString();
        $flow = $request->string('flow')->toString();
        $environment = $request->string('environment')->toString();
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();

        return EcfDocument::query()
            ->with(['document.client:id,business_name', 'document.currency:id,code'])
            ->when(! $user->can(Invoice::VIEW_ALL_PERMISSION), fn (Builder $query) => $query->whereHasMorph(
                'document',
                [Invoice::class, CreditNote::class, DebitNote::class],
                fn (Builder $document) => $document->visibleTo($user),
            ))
            ->when($status !== '', fn (Builder $query) => $query->where('status', $status))
            ->when($flow !== '', fn (Builder $query) => $query->where('flow', $flow))
            ->when($environment !== '', fn (Builder $query) => $query->where('environment', $environment))
            ->when($from !== '', fn (Builder $query) => $query->whereDate('created_at', '>=', $from))
            ->when($to !== '', fn (Builder $query) => $query->whereDate('created_at', '<=', $to));
    }

    /** @return array<string, mixed> */
    private function indexRow(EcfDocument $ecfDocument): array
    {
        return [
            'id' => $ecfDocument->id,
            'encf' => $ecfDocument->encf,
            'ncf_type' => $ecfDocument->ncf_type->value,
            'type_label' => $ecfDocument->ncf_type->label(),
            'flow' => $ecfDocument->flow,
            'status' => $ecfDocument->status->value,
            'status_label' => $ecfDocument->status->label(),
            'environment' => $ecfDocument->environment->value,
            'track_id' => $ecfDocument->track_id,
            'attempts' => $ecfDocument->attempts,
            'created_at' => $ecfDocument->created_at?->toIso8601String(),
            'signed_at' => $ecfDocument->signed_at?->toIso8601String(),
            'document' => $this->documentSummary($ecfDocument->document),
        ];
    }

    /** @return array{kind: string, id: int, number: string|null, client_name: string|null, total: string, currency: string|null, url: string} */
    private function documentSummary(?Model $document): array
    {
        return match (true) {
            $document instanceof Invoice => [
                'kind' => 'invoice', 'id' => $document->id, 'number' => $document->number, 'client_name' => $document->client_name,
                'total' => $document->total, 'currency' => $document->currency?->code, 'url' => route('sales.invoices.show', $document),
            ],
            $document instanceof CreditNote => [
                'kind' => 'credit_note', 'id' => $document->id, 'number' => $document->number, 'client_name' => $document->client?->business_name,
                'total' => $document->total, 'currency' => $document->currency?->code, 'url' => route('sales.credit-notes.show', $document),
            ],
            $document instanceof DebitNote => [
                'kind' => 'debit_note', 'id' => $document->id, 'number' => $document->number, 'client_name' => $document->client?->business_name,
                'total' => $document->total, 'currency' => $document->currency?->code, 'url' => route('sales.debit-notes.show', $document),
            ],
            default => throw new \LogicException('Documento e-CF sin tipo de comprobante soportado'),
        };
    }
}
