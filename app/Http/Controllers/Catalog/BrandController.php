<?php

namespace App\Http\Controllers\Catalog;

use App\Exports\TableExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreBrandRequest;
use App\Http\Requests\Catalog\UpdateBrandRequest;
use App\Models\Brand;
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

class BrandController extends Controller
{
    #[Middleware('permission:catalog.brands.view')]
    public function index(Request $request): Response
    {
        $rows = TableQuery::apply(Brand::query(), $request, ['name'], ['name', 'is_active'], 'name', 'asc');

        return Inertia::render('catalog/brands/index', [
            'rows' => $rows,
            'filters' => $request->only(['search', 'sort', 'dir', 'per_page']),
        ]);
    }

    #[Middleware('permission:catalog.brands.create')]
    public function create(): Response
    {
        return Inertia::render('catalog/brands/form');
    }

    #[Middleware('permission:catalog.brands.create')]
    public function store(StoreBrandRequest $request): RedirectResponse
    {
        Brand::create($request->validated());

        return redirect()->route('catalog.brands.index')->with('success', 'Marca creada');
    }

    #[Middleware('permission:catalog.brands.update')]
    public function edit(Brand $brand): Response
    {
        return Inertia::render('catalog/brands/form', [
            'brand' => $brand,
            'history' => AuditHistory::for($brand),
        ]);
    }

    #[Middleware('permission:catalog.brands.update')]
    public function update(UpdateBrandRequest $request, Brand $brand): RedirectResponse
    {
        $brand->update($request->validated());

        return redirect()->route('catalog.brands.index')->with('success', 'Marca actualizada');
    }

    #[Middleware('permission:catalog.brands.delete')]
    public function destroy(Brand $brand): RedirectResponse
    {
        try {
            DB::transaction(fn () => $brand->delete());
        } catch (QueryException $e) {
            if ($e->getCode() === '23503') {
                return back()->with('error', 'No se puede eliminar: el registro está en uso');
            }
            throw $e;
        }

        return redirect()->route('catalog.brands.index')->with('success', 'Marca eliminada');
    }

    #[Middleware('permission:catalog.brands.export')]
    public function export(Request $request): BinaryFileResponse
    {
        $query = TableQuery::applyFilters(Brand::query(), $request, ['name'], ['name', 'is_active'], 'name', 'asc');

        return TableExport::fromQuery($query, 'brands', ['Nombre' => 'name', 'Activa' => fn (Brand $b) => $b->is_active ? 'Sí' : 'No'], $request->string('format', 'xlsx')->toString());
    }
}
