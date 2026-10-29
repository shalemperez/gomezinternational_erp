<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\SaveWarehouse;
use App\Exports\TableExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreWarehouseRequest;
use App\Http\Requests\Inventory\UpdateWarehouseRequest;
use App\Models\Warehouse;
use App\Support\AuditHistory;
use App\Support\TableQuery;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class WarehouseController extends Controller
{
    #[Middleware('permission:inventory.warehouses.view')]
    public function index(Request $request): Response
    {
        $rows = TableQuery::apply(Warehouse::query(), $request, ['code', 'name'], ['code', 'name', 'is_default', 'is_active'], 'code', 'asc');

        return Inertia::render('inventory/warehouses/index', [
            'rows' => $rows,
            'filters' => $request->only(['search', 'sort', 'dir', 'per_page']),
        ]);
    }

    #[Middleware('permission:inventory.warehouses.manage')]
    public function create(): Response
    {
        return Inertia::render('inventory/warehouses/form');
    }

    #[Middleware('permission:inventory.warehouses.manage')]
    public function store(StoreWarehouseRequest $request, SaveWarehouse $saveWarehouse): RedirectResponse
    {
        $saveWarehouse->handle($request->validated());

        return redirect()->route('inventory.warehouses.index')->with('success', 'Almacén creado');
    }

    #[Middleware('permission:inventory.warehouses.manage')]
    public function edit(Warehouse $warehouse): Response
    {
        return Inertia::render('inventory/warehouses/form', [
            'warehouse' => $warehouse,
            'history' => AuditHistory::for($warehouse),
        ]);
    }

    #[Middleware('permission:inventory.warehouses.manage')]
    public function update(UpdateWarehouseRequest $request, Warehouse $warehouse, SaveWarehouse $saveWarehouse): RedirectResponse
    {
        $saveWarehouse->handle($request->validated(), $warehouse);

        return redirect()->route('inventory.warehouses.index')->with('success', 'Almacén actualizado');
    }

    #[Middleware('permission:inventory.warehouses.manage')]
    public function destroy(Warehouse $warehouse): RedirectResponse
    {
        if ($warehouse->is_default) {
            return back()->with('error', 'No se puede eliminar el almacén predeterminado.');
        }

        try {
            DB::transaction(fn () => $warehouse->delete());
        } catch (QueryException $e) {
            if ($e->getCode() === '23503') {
                return back()->with('error', 'No se puede eliminar: el registro está en uso');
            }
            throw $e;
        }

        return redirect()->route('inventory.warehouses.index')->with('success', 'Almacén eliminado');
    }

    #[Middleware('permission:inventory.warehouses.export')]
    public function export(Request $request): BinaryFileResponse
    {
        $query = TableQuery::applyFilters(Warehouse::query(), $request, ['code', 'name'], ['code', 'name', 'is_default', 'is_active'], 'code', 'asc');

        return TableExport::fromQuery($query, 'warehouses', [
            'Código' => 'code',
            'Nombre' => 'name',
            'Dirección' => 'address',
            'Predeterminado' => fn (Warehouse $w) => $w->is_default ? 'Sí' : 'No',
            'Activo' => fn (Warehouse $w) => $w->is_active ? 'Sí' : 'No',
        ], $request->string('format', 'xlsx')->toString());
    }
}
