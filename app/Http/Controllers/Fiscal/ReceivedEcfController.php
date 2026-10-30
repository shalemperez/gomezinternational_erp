<?php

namespace App\Http\Controllers\Fiscal;

use App\Exports\TableExport;
use App\Http\Controllers\Controller;
use App\Models\ReceivedEcf;
use App\Models\User;
use App\Support\AuditHistory;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ReceivedEcfController extends Controller
{
    /** @var list<string> */
    private const SEARCHABLE = ['received_ecfs.rnc_emisor', 'received_ecfs.razon_social_emisor', 'received_ecfs.encf'];

    /** @var list<string> */
    private const SORTABLE = ['encf', 'rnc_emisor', 'fecha_emision', 'monto_total', 'received_at', 'commercial_status'];

    #[Middleware('permission:fiscal.received-ecf.view')]
    public function index(Request $request): Response
    {
        $rows = TableQuery::apply($this->filteredQuery($request), $request, self::SEARCHABLE, self::SORTABLE, 'received_at', 'desc')
            ->through(fn (ReceivedEcf $receivedEcf) => $this->indexRow($receivedEcf));

        return Inertia::render('fiscal/received-ecfs/index', [
            'rows' => $rows,
            'filters' => $request->only(['search', 'sort', 'dir', 'per_page', 'commercial_status', 'from', 'to']),
            'commercialStatuses' => [
                ['value' => 'pending', 'label' => 'Pendiente'],
                ['value' => 'approved', 'label' => 'Aprobado'],
                ['value' => 'rejected', 'label' => 'Rechazado'],
            ],
        ]);
    }

    #[Middleware('permission:fiscal.received-ecf.view')]
    public function show(Request $request, ReceivedEcf $receivedEcf): Response
    {
        $receivedEcf->loadMissing('supplier:id,name');

        return Inertia::render('fiscal/received-ecfs/show', [
            'receivedEcf' => $this->detail($receivedEcf),
            'history' => AuditHistory::for($receivedEcf),
            'can' => ['approve' => $this->actor($request)->can('fiscal.received-ecf.approve')],
        ]);
    }

    #[Middleware('permission:fiscal.received-ecf.view')]
    public function xml(Request $request, ReceivedEcf $receivedEcf): SymfonyResponse
    {
        $path = match ($request->string('file', 'ecf')->toString()) {
            'arecf' => $receivedEcf->arecf_path,
            'acecf' => $receivedEcf->acecf_path,
            default => $receivedEcf->xml_path,
        };
        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, basename($path));
    }

    #[Middleware('permission:fiscal.received-ecf.export')]
    public function export(Request $request): BinaryFileResponse
    {
        $query = TableQuery::applyFilters($this->filteredQuery($request), $request, self::SEARCHABLE, self::SORTABLE, 'received_at', 'desc');

        return TableExport::fromQuery($query, 'received-ecfs', [
            'RNC emisor' => 'rnc_emisor',
            'Razón social' => 'razon_social_emisor',
            'e-NCF' => 'encf',
            'Fecha emisión' => fn (ReceivedEcf $receivedEcf) => $receivedEcf->fecha_emision?->format('d/m/Y'),
            'Monto total' => 'monto_total',
            'ITBIS' => 'total_itbis',
            'Recibido' => fn (ReceivedEcf $receivedEcf) => $receivedEcf->statusLabel(),
            'Aprobación comercial' => fn (ReceivedEcf $receivedEcf) => $receivedEcf->commercialStatusLabel(),
            'Fecha recepción' => fn (ReceivedEcf $receivedEcf) => $receivedEcf->received_at->format('d/m/Y H:i'),
        ], $request->string('format', 'xlsx')->toString());
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /** @return Builder<ReceivedEcf> */
    private function filteredQuery(Request $request): Builder
    {
        $commercialStatus = $request->string('commercial_status')->toString();
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();

        return ReceivedEcf::query()
            ->with('supplier:id,name')
            ->when($commercialStatus !== '', fn (Builder $query) => $query->where('commercial_status', $commercialStatus))
            ->when($from !== '', fn (Builder $query) => $query->whereDate('received_at', '>=', $from))
            ->when($to !== '', fn (Builder $query) => $query->whereDate('received_at', '<=', $to));
    }

    /** @return array<string, mixed> */
    private function indexRow(ReceivedEcf $receivedEcf): array
    {
        return [
            'id' => $receivedEcf->id,
            'rnc_emisor' => $receivedEcf->rnc_emisor,
            'razon_social_emisor' => $receivedEcf->razon_social_emisor,
            'encf' => $receivedEcf->encf,
            'fecha_emision' => $receivedEcf->fecha_emision?->toDateString(),
            'monto_total' => $receivedEcf->monto_total,
            'status_label' => $receivedEcf->statusLabel(),
            'commercial_status' => $receivedEcf->commercial_status,
            'commercial_status_label' => $receivedEcf->commercialStatusLabel(),
            'received_at' => $receivedEcf->received_at->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(ReceivedEcf $receivedEcf): array
    {
        return [
            'id' => $receivedEcf->id,
            'rnc_emisor' => $receivedEcf->rnc_emisor,
            'razon_social_emisor' => $receivedEcf->razon_social_emisor,
            'ncf_type' => $receivedEcf->ncf_type,
            'encf' => $receivedEcf->encf,
            'rnc_comprador' => $receivedEcf->rnc_comprador,
            'fecha_emision' => $receivedEcf->fecha_emision?->toDateString(),
            'monto_total' => $receivedEcf->monto_total,
            'total_itbis' => $receivedEcf->total_itbis,
            'received_at' => $receivedEcf->received_at->toIso8601String(),
            'sender_ip' => $receivedEcf->sender_ip,
            'arecf_status' => $receivedEcf->arecf_status,
            'status_label' => $receivedEcf->statusLabel(),
            'arecf_reason_code' => $receivedEcf->arecf_reason_code,
            'commercial_status' => $receivedEcf->commercial_status,
            'commercial_status_label' => $receivedEcf->commercialStatusLabel(),
            'commercial_reason' => $receivedEcf->commercial_reason,
            'commercial_sent_at' => $receivedEcf->commercial_sent_at?->toIso8601String(),
            'supplier' => $receivedEcf->supplier === null ? null : [
                'id' => $receivedEcf->supplier->id,
                'name' => $receivedEcf->supplier->name,
                'url' => route('catalog.suppliers.edit', $receivedEcf->supplier),
            ],
            'xml_url' => route('fiscal.received-ecfs.xml', $receivedEcf),
            'arecf_url' => $receivedEcf->arecf_path === null ? null : route('fiscal.received-ecfs.xml', [$receivedEcf, 'file' => 'arecf']),
            'acecf_url' => $receivedEcf->acecf_path === null ? null : route('fiscal.received-ecfs.xml', [$receivedEcf, 'file' => 'acecf']),
        ];
    }
}
