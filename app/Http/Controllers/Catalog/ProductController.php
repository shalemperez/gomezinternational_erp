<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\SaveProduct;
use App\Enums\ItbisRate;
use App\Exports\TableExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductRequest;
use App\Http\Requests\Catalog\UpdateProductRequest;
use App\Models\AdditionalTax;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\UnitOfMeasure;
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

class ProductController extends Controller
{
    #[Middleware('permission:catalog.products.view')]
    public function index(Request $request): Response
    {
        $rows = TableQuery::apply($this->filterQuery(Product::query()->with(['brand:id,name', 'category:id,name', 'unit:id,code']), $request), $request, ['products.sku', 'products.barcode', 'products.name'], ['sku', 'name', 'is_active'], 'name', 'asc');

        return Inertia::render('catalog/products/index', [
            'rows' => $rows,
            'filters' => $request->only(['search', 'sort', 'dir', 'per_page', 'brand_id', 'category_id', 'status']),
            'brands' => Brand::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'categories' => ProductCategory::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    #[Middleware('permission:catalog.products.create')]
    public function create(): Response
    {
        return Inertia::render('catalog/products/form', $this->formOptions());
    }

    #[Middleware('permission:catalog.products.create')]
    public function store(StoreProductRequest $request, SaveProduct $saveProduct): RedirectResponse
    {
        $saveProduct->handle($request->validated());

        return redirect()->route('catalog.products.index')->with('success', 'Producto creado');
    }

    #[Middleware('permission:catalog.products.update')]
    public function edit(Product $product): Response
    {
        return Inertia::render('catalog/products/form', [
            'product' => [...$product->toArray(), 'additional_tax_ids' => $product->additionalTaxes()->pluck('additional_taxes.id')->all()],
            'history' => AuditHistory::for($product),
            ...$this->formOptions($product),
        ]);
    }

    #[Middleware('permission:catalog.products.update')]
    public function update(UpdateProductRequest $request, Product $product, SaveProduct $saveProduct): RedirectResponse
    {
        $saveProduct->handle($request->validated(), $product);

        return redirect()->route('catalog.products.index')->with('success', 'Producto actualizado');
    }

    #[Middleware('permission:catalog.products.delete')]
    public function destroy(Product $product): RedirectResponse
    {
        try {
            DB::transaction(fn () => $product->delete());
        } catch (QueryException $e) {
            if ($e->getCode() === '23503') {
                return back()->with('error', 'No se puede eliminar: el registro está en uso');
            }
            throw $e;
        }

        return redirect()->route('catalog.products.index')->with('success', 'Producto eliminado');
    }

    #[Middleware('permission:catalog.products.export')]
    public function export(Request $request): BinaryFileResponse
    {
        $query = TableQuery::applyFilters($this->filterQuery(Product::query()->with(['brand', 'category', 'unit', 'additionalTaxes']), $request), $request, ['products.sku', 'products.barcode', 'products.name'], ['sku', 'name', 'is_active'], 'name', 'asc');

        return TableExport::fromQuery($query, 'products', [
            'SKU' => 'sku',
            'Código de barras' => 'barcode',
            'Nombre' => 'name',
            'Marca' => 'brand.name',
            'Categoría' => 'category.name',
            'Unidad' => 'unit.code',
            'Unidades por caja' => 'units_per_box',
            'Peso (kg)' => 'weight_kg',
            'Volumen (L)' => 'volume_liters',
            'Grado alcohólico' => 'alcohol_degree',
            'ITBIS %' => 'itbis_rate',
            'Controla lotes' => fn (Product $p) => $p->tracks_lots ? 'Sí' : 'No',
            'Código arancelario' => 'tariff_code',
            'Stock mínimo' => 'minimum_stock',
            'Impuestos adicionales' => fn (Product $p) => $p->additionalTaxes->pluck('code')->implode(', '),
            'Activo' => fn (Product $p) => $p->is_active ? 'Sí' : 'No',
        ], $request->string('format', 'xlsx')->toString());
    }

    /**
     * @template TModel of Product
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function filterQuery(Builder $query, Request $request): Builder
    {
        return $query
            ->when($request->filled('brand_id'), fn (Builder $q) => $q->where('brand_id', $request->integer('brand_id')))
            ->when($request->filled('category_id'), fn (Builder $q) => $q->where('category_id', $request->integer('category_id')))
            ->when($request->string('status')->toString() === 'active', fn (Builder $q) => $q->where('is_active', true))
            ->when($request->string('status')->toString() === 'inactive', fn (Builder $q) => $q->where('is_active', false));
    }

    /**
     * Active options, plus the values already set on the product so an inactive one stays selectable.
     *
     * @return array<string, mixed>
     */
    private function formOptions(?Product $product = null): array
    {
        $selectedTaxIds = $product?->additionalTaxes()->pluck('additional_taxes.id')->all() ?? [];

        return [
            'brands' => Brand::query()->where(fn (Builder $q) => $q->where('is_active', true)->orWhereKey($product?->brand_id))->orderBy('name')->get(['id', 'name']),
            'categories' => ProductCategory::query()->where(fn (Builder $q) => $q->where('is_active', true)->orWhereKey($product?->category_id))->orderBy('name')->get(['id', 'name']),
            'units' => UnitOfMeasure::query()->where(fn (Builder $q) => $q->where('is_active', true)->orWhereKey($product?->unit_of_measure_id))->orderBy('code')->get(['id', 'code', 'name']),
            'additionalTaxes' => AdditionalTax::query()->where(fn (Builder $q) => $q->where('is_active', true)->orWhereKey($selectedTaxIds))->orderBy('code')->get(['id', 'code', 'name', 'calculation_type']),
            'itbisOptions' => collect(ItbisRate::cases())->map(fn (ItbisRate $c) => ['value' => $c->value, 'label' => $c->label()])->values()->all(),
        ];
    }
}
