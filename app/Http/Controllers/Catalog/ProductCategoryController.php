<?php

namespace App\Http\Controllers\Catalog;

use App\Exports\TableExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductCategoryRequest;
use App\Http\Requests\Catalog\UpdateProductCategoryRequest;
use App\Models\ProductCategory;
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

class ProductCategoryController extends Controller
{
    #[Middleware('permission:catalog.categories.view')]
    public function index(Request $request): Response
    {
        $rows = TableQuery::apply(ProductCategory::query()->with('parent:id,name'), $request, ['name'], ['name', 'is_active'], 'name', 'asc');

        return Inertia::render('catalog/categories/index', [
            'rows' => $rows,
            'filters' => $request->only(['search', 'sort', 'dir', 'per_page']),
        ]);
    }

    #[Middleware('permission:catalog.categories.create')]
    public function create(): Response
    {
        return Inertia::render('catalog/categories/form', [
            'parents' => ProductCategory::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    #[Middleware('permission:catalog.categories.create')]
    public function store(StoreProductCategoryRequest $request): RedirectResponse
    {
        ProductCategory::create($request->validated());

        return redirect()->route('catalog.categories.index')->with('success', 'Categoría creada');
    }

    #[Middleware('permission:catalog.categories.update')]
    public function edit(ProductCategory $category): Response
    {
        return Inertia::render('catalog/categories/form', [
            'category' => $category,
            'history' => AuditHistory::for($category),
            'parents' => ProductCategory::query()->where('is_active', true)->whereKeyNot($category->id)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    #[Middleware('permission:catalog.categories.update')]
    public function update(UpdateProductCategoryRequest $request, ProductCategory $category): RedirectResponse
    {
        $category->update($request->validated());

        return redirect()->route('catalog.categories.index')->with('success', 'Categoría actualizada');
    }

    #[Middleware('permission:catalog.categories.delete')]
    public function destroy(ProductCategory $category): RedirectResponse
    {
        try {
            DB::transaction(fn () => $category->delete());
        } catch (QueryException $e) {
            if ($e->getCode() === '23503') {
                return back()->with('error', 'No se puede eliminar: el registro está en uso');
            }
            throw $e;
        }

        return redirect()->route('catalog.categories.index')->with('success', 'Categoría eliminada');
    }

    #[Middleware('permission:catalog.categories.export')]
    public function export(Request $request): BinaryFileResponse
    {
        $query = TableQuery::applyFilters(ProductCategory::query()->with('parent:id,name'), $request, ['name'], ['name', 'is_active'], 'name', 'asc');

        return TableExport::fromQuery($query, 'categories', ['Nombre' => 'name', 'Categoría padre' => 'parent.name', 'Activa' => fn (ProductCategory $c) => $c->is_active ? 'Sí' : 'No'], $request->string('format', 'xlsx')->toString());
    }
}
