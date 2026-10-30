<?php

namespace App\Http\Controllers\Catalog;

use App\Exports\TableExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreUnitOfMeasureRequest;
use App\Http\Requests\Catalog\UpdateUnitOfMeasureRequest;
use App\Models\UnitOfMeasure;
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

class UnitOfMeasureController extends Controller
{
    #[Middleware('permission:catalog.units.view')]
    public function index(Request $request): Response
    {
        $rows = TableQuery::apply(UnitOfMeasure::query(), $request, ['code', 'name'], ['code', 'name', 'is_active'], 'code', 'asc');

        return Inertia::render('catalog/units/index', [
            'rows' => $rows,
            'filters' => $request->only(['search', 'sort', 'dir', 'per_page']),
        ]);
    }

    #[Middleware('permission:catalog.units.create')]
    public function create(): Response
    {
        return Inertia::render('catalog/units/form');
    }

    #[Middleware('permission:catalog.units.create')]
    public function store(StoreUnitOfMeasureRequest $request): RedirectResponse
    {
        UnitOfMeasure::create($request->validated());

        return redirect()->route('catalog.units.index')->with('success', 'Unidad de medida creada');
    }

    #[Middleware('permission:catalog.units.update')]
    public function edit(UnitOfMeasure $unit): Response
    {
        return Inertia::render('catalog/units/form', [
            'unit' => $unit,
            'history' => AuditHistory::for($unit),
        ]);
    }

    #[Middleware('permission:catalog.units.update')]
    public function update(UpdateUnitOfMeasureRequest $request, UnitOfMeasure $unit): RedirectResponse
    {
        $unit->update($request->validated());

        return redirect()->route('catalog.units.index')->with('success', 'Unidad de medida actualizada');
    }

    #[Middleware('permission:catalog.units.delete')]
    public function destroy(UnitOfMeasure $unit): RedirectResponse
    {
        try {
            DB::transaction(fn () => $unit->delete());
        } catch (QueryException $e) {
            if ($e->getCode() === '23503') {
                return back()->with('error', 'No se puede eliminar: el registro está en uso');
            }
            throw $e;
        }

        return redirect()->route('catalog.units.index')->with('success', 'Unidad de medida eliminada');
    }

    #[Middleware('permission:catalog.units.export')]
    public function export(Request $request): BinaryFileResponse
    {
        $query = TableQuery::applyFilters(UnitOfMeasure::query(), $request, ['code', 'name'], ['code', 'name', 'is_active'], 'code', 'asc');

        return TableExport::fromQuery($query, 'units', ['Código' => 'code', 'Nombre' => 'name', 'Activa' => fn (UnitOfMeasure $u) => $u->is_active ? 'Sí' : 'No'], $request->string('format', 'xlsx')->toString());
    }
}
