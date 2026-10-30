<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\ConfirmDispatch;
use App\Actions\Inventory\SaveDispatch;
use App\Enums\DispatchStatus;
use App\Enums\InvoiceStatus;
use App\Exports\TableExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\ConfirmDispatchRequest;
use App\Http\Requests\Inventory\StoreDispatchRequest;
use App\Models\Dispatch;
use App\Models\DispatchLine;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\StockBalance;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Documents\DocumentPdf;
use App\Services\Inventory\InventoryService;
use App\Support\AuditHistory;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class DispatchController extends Controller
{
    public function __construct(private readonly InventoryService $inventory) {}

    #[Middleware('permission:inventory.dispatches.view')]
    public function index(Request $request): Response
    {
        $canViewCosts = $this->actor($request)->can('inventory.stock.view-costs');
        $rows = TableQuery::apply($this->filteredQuery($request), $request, ['dispatches.number', 'dispatches.driver_name', 'dispatches.vehicle'], ['number', 'dispatch_date', 'status'], 'dispatch_date', 'desc')
            ->through(fn (Dispatch $dispatch) => [...$this->dispatchRow($dispatch, $canViewCosts), 'status_label' => $dispatch->status->label()]);

        return Inertia::render('inventory/dispatches/index', [
            'rows' => $rows,
            'filters' => $request->only(['search', 'sort', 'dir', 'per_page', 'status', 'warehouse_id', 'from', 'to']),
            'statuses' => array_map(fn (DispatchStatus $status) => ['value' => $status->value, 'label' => $status->label()], DispatchStatus::cases()),
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    #[Middleware('permission:inventory.dispatches.create')]
    public function create(Request $request): Response|RedirectResponse
    {
        $invoiceId = $request->integer('invoice');
        $invoice = $invoiceId > 0 ? $this->loadInvoice($invoiceId) : null;
        if ($invoice === null) {
            return redirect()->route('sales.invoices.index')->with('error', 'Selecciona la factura a la que aplica el conduce');
        }
        if (! in_array($invoice->status, [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid, InvoiceStatus::Paid], true)) {
            return redirect()->route('sales.invoices.show', $invoice)->with('error', 'La factura debe estar emitida.');
        }

        return Inertia::render('inventory/dispatches/form', [
            'invoice' => $this->invoiceProps($invoice),
            'lots' => $this->stockedLots($invoice),
        ]);
    }

    #[Middleware('permission:inventory.dispatches.create')]
    public function store(StoreDispatchRequest $request, SaveDispatch $saveDispatch): RedirectResponse
    {
        $invoice = Invoice::query()->whereKey($request->integer('invoice_id'))->firstOrFail();
        $dispatch = $saveDispatch->handle($request->validated(), $invoice, $this->actor($request));

        return redirect()->route('inventory.dispatches.show', $dispatch)->with('success', 'Conduce guardado como borrador');
    }

    #[Middleware('permission:inventory.dispatches.view')]
    public function show(Request $request, Dispatch $dispatch): Response
    {
        $user = $this->actor($request);
        $dispatch->loadMissing(['invoice:id,number,ncf', 'client:id,business_name', 'warehouse:id,name', 'confirmer:id,name', 'lines.product:id,sku,name', 'lines.lot:id,code,expires_on']);

        return Inertia::render('inventory/dispatches/show', [
            'dispatch' => $this->dispatchDetail($dispatch, $user->can('inventory.stock.view-costs')),
            'history' => AuditHistory::for($dispatch),
            'can' => [
                'update' => $user->can('inventory.dispatches.create') && $dispatch->status === DispatchStatus::Draft,
                'delete' => $user->can('inventory.dispatches.create') && $dispatch->status === DispatchStatus::Draft,
                'confirm' => $user->can('inventory.dispatches.confirm') && $dispatch->status === DispatchStatus::Draft,
                'expiredOverride' => $user->can('inventory.dispatches.expired-override'),
            ],
        ]);
    }

    #[Middleware('permission:inventory.dispatches.create')]
    public function edit(Request $request, Dispatch $dispatch): Response|RedirectResponse
    {
        if ($dispatch->status !== DispatchStatus::Draft) {
            return redirect()->route('inventory.dispatches.show', $dispatch)->with('error', 'Solo se pueden editar conduces en borrador');
        }
        $dispatch->loadMissing(['lines']);
        $invoice = $this->loadInvoice($dispatch->invoice_id);

        return Inertia::render('inventory/dispatches/form', [
            'invoice' => $this->invoiceProps($invoice),
            'lots' => $this->stockedLots($invoice),
            'dispatch' => [
                'id' => $dispatch->id,
                'dispatch_date' => $dispatch->dispatch_date->toDateString(),
                'driver_name' => $dispatch->driver_name,
                'vehicle' => $dispatch->vehicle,
                'notes' => $dispatch->notes,
                'lines' => $dispatch->lines->map(fn (DispatchLine $line) => ['invoice_line_id' => $line->invoice_line_id, 'lot_id' => $line->lot_id, 'quantity' => $line->quantity])->values()->all(),
            ],
        ]);
    }

    #[Middleware('permission:inventory.dispatches.create')]
    public function update(StoreDispatchRequest $request, Dispatch $dispatch, SaveDispatch $saveDispatch): RedirectResponse
    {
        if ($dispatch->status !== DispatchStatus::Draft) {
            return back()->with('error', 'Solo se pueden editar conduces en borrador');
        }
        $invoice = Invoice::query()->whereKey($request->integer('invoice_id'))->firstOrFail();
        $saved = $saveDispatch->handle($request->validated(), $invoice, $this->actor($request), $dispatch);

        return redirect()->route('inventory.dispatches.show', $saved)->with('success', 'Conduce actualizado');
    }

    #[Middleware('permission:inventory.dispatches.create')]
    public function destroy(Dispatch $dispatch): RedirectResponse
    {
        if ($dispatch->status !== DispatchStatus::Draft) {
            return back()->with('error', 'Solo se pueden eliminar conduces en borrador');
        }

        try {
            DB::transaction(fn () => $dispatch->delete());
        } catch (QueryException $e) {
            if ($e->getCode() === '23503') {
                return back()->with('error', 'No se puede eliminar: el registro está en uso');
            }
            throw $e;
        }

        return redirect()->route('inventory.dispatches.index')->with('success', 'Borrador de conduce eliminado');
    }

    #[Middleware('permission:inventory.dispatches.confirm')]
    public function confirm(ConfirmDispatchRequest $request, Dispatch $dispatch, ConfirmDispatch $confirmDispatch): RedirectResponse
    {
        $user = $this->actor($request);
        $override = $request->boolean('expired_override');
        if ($override && ! $user->can('inventory.dispatches.expired-override')) {
            abort(403);
        }

        $confirmed = $confirmDispatch->handle($dispatch, $user, $override, $request->string('expired_override_reason')->trim()->toString() ?: null);

        return redirect()->route('inventory.dispatches.show', $confirmed)->with('success', "Conduce {$confirmed->number} confirmado");
    }

    #[Middleware('permission:inventory.dispatches.view')]
    public function pdf(Dispatch $dispatch, DocumentPdf $pdf): SymfonyResponse
    {
        activity('dispatches')->performedOn($dispatch)->event('pdf_generated')->log('PDF de '.($dispatch->number ?? 'borrador').' generado');

        return $pdf->stream('pdf.inventory.dispatch', $this->pdfData($dispatch), ($dispatch->number ?? 'borrador-'.$dispatch->id).'.pdf');
    }

    #[Middleware('permission:inventory.dispatches.export')]
    public function export(Request $request): BinaryFileResponse
    {
        $query = TableQuery::applyFilters($this->filteredQuery($request), $request, ['dispatches.number', 'dispatches.driver_name', 'dispatches.vehicle'], ['number', 'dispatch_date', 'status'], 'dispatch_date', 'desc');

        return TableExport::fromQuery($query, 'dispatches', [
            'Número' => 'number',
            'Fecha' => fn (Dispatch $dispatch) => $dispatch->dispatch_date->format('d/m/Y'),
            'Factura' => 'invoice.number',
            'NCF factura' => 'invoice.ncf',
            'Cliente' => 'client.business_name',
            'Almacén' => 'warehouse.name',
            'Chofer' => 'driver_name',
            'Vehículo' => 'vehicle',
            'Estado' => fn (Dispatch $dispatch) => $dispatch->status->label(),
        ], $request->string('format', 'xlsx')->toString());
    }

    /** @return array<string, mixed> */
    public function pdfData(Dispatch $dispatch): array
    {
        $dispatch->load(['invoice', 'lines.product', 'lines.lot']);

        return [
            'dispatch' => $dispatch,
            'lines' => $dispatch->lines->map(fn (DispatchLine $line) => [
                'product' => $line->product->sku.' — '.$line->product->name,
                'lot' => $line->lot_id === null ? '' : $line->lot->code,
                'expires_on' => $line->lot?->expires_on?->format('d/m/Y') ?? '',
                'quantity' => $line->quantity,
            ])->values()->all(),
            'copy' => 'Original',
        ];
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function loadInvoice(int $invoiceId): Invoice
    {
        return Invoice::query()->with(['lines.product:id,sku,name,tracks_lots', 'client:id,business_name', 'warehouse:id,name'])->whereKey($invoiceId)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function invoiceProps(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'ncf' => $invoice->ncf,
            'client_name' => $invoice->client_name,
            'warehouse' => ['id' => $invoice->warehouse?->id, 'name' => $invoice->warehouse?->name],
            'lines' => $invoice->lines->map(fn (InvoiceLine $line) => [
                'id' => $line->id,
                'product' => ['id' => $line->product->id, 'sku' => $line->product->sku, 'name' => $line->product->name, 'tracks_lots' => $line->product->tracks_lots],
                'quantity' => $line->quantity,
                'dispatched_quantity' => $line->dispatched_quantity,
                'credited_quantity' => $line->credited_quantity,
                'remaining' => $line->remainingToDispatch(),
                'suggested_lots' => $line->product->tracks_lots
                    ? $this->inventory->suggestLots($line->product_id, (int) $invoice->warehouse_id, $line->remainingToDispatch())
                    : [],
            ])->values()->all(),
        ];
    }

    /** @return array<int, array{id: int, product_id: int, code: string, expires_on: string|null, quantity: string, expired: bool}> */
    private function stockedLots(Invoice $invoice): array
    {
        $productIds = $invoice->lines->filter(fn (InvoiceLine $line) => $line->product->tracks_lots)->pluck('product_id')->unique()->values()->all();

        return StockBalance::query()->with('lot:id,code,expires_on,product_id')
            ->where('warehouse_id', $invoice->warehouse_id)->whereNotNull('lot_id')->where('quantity', '>', 0)->whereIn('product_id', $productIds)
            ->get()
            ->sortBy(fn (StockBalance $balance) => $balance->lot->expires_on?->getTimestamp() ?? PHP_INT_MAX)
            ->map(fn (StockBalance $balance) => [
                'id' => (int) $balance->lot_id, 'product_id' => $balance->product_id, 'code' => $balance->lot->code,
                'expires_on' => $balance->lot->expires_on?->toDateString(), 'quantity' => (string) $balance->quantity,
                'expired' => $balance->lot->expires_on !== null && $balance->lot->expires_on->toDateString() < today()->toDateString(),
            ])->values()->all();
    }

    /** @return array<string, mixed> */
    private function dispatchRow(Dispatch $dispatch, bool $canViewCosts): array
    {
        if (! $canViewCosts) {
            $dispatch->makeHidden('total_cost');
        }

        return [...$dispatch->toArray(), 'status_label' => $dispatch->status->label()];
    }

    /** @return array<string, mixed> */
    private function dispatchDetail(Dispatch $dispatch, bool $canViewCosts): array
    {
        if (! $canViewCosts) {
            $dispatch->makeHidden('total_cost');
            $dispatch->lines->each->makeHidden(['unit_cost', 'total_cost']);
        }

        return [
            ...$dispatch->toArray(),
            'status_label' => $dispatch->status->label(),
            'has_expired_lots' => $dispatch->lines->contains(fn (DispatchLine $line) => $line->lot?->expires_on !== null && $line->lot->expires_on->toDateString() < $dispatch->dispatch_date->toDateString()),
        ];
    }

    /** @return Builder<Dispatch> */
    private function filteredQuery(Request $request): Builder
    {
        $status = $request->string('status')->toString();
        $warehouseId = $request->integer('warehouse_id');
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();

        return Dispatch::query()
            ->with(['invoice:id,number,ncf', 'client:id,business_name', 'warehouse:id,name'])
            ->when($status !== '', fn (Builder $query) => $query->where('status', $status))
            ->when($warehouseId > 0, fn (Builder $query) => $query->where('warehouse_id', $warehouseId))
            ->when($from !== '', fn (Builder $query) => $query->whereDate('dispatch_date', '>=', $from))
            ->when($to !== '', fn (Builder $query) => $query->whereDate('dispatch_date', '<=', $to));
    }
}
