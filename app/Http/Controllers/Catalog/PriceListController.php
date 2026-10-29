<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\SavePriceList;
use App\Actions\Catalog\SavePriceListItems;
use App\Exports\TableExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StorePriceListRequest;
use App\Http\Requests\Catalog\UpdatePriceListItemsRequest;
use App\Http\Requests\Catalog\UpdatePriceListRequest;
use App\Models\Currency;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
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

class PriceListController extends Controller
{
    #[Middleware('permission:catalog.price-lists.view')]
    public function index(Request $request): Response
    {
        $rows = TableQuery::apply(PriceList::query()->with('currency:id,code'), $request, ['name'], ['name', 'is_default', 'is_active'], 'name', 'asc');

        return Inertia::render('catalog/price-lists/index', [
            'rows' => $rows,
            'filters' => $request->only(['search', 'sort', 'dir', 'per_page']),
        ]);
    }

    #[Middleware('permission:catalog.price-lists.create')]
    public function create(): Response
    {
        return Inertia::render('catalog/price-lists/form', [
            'currencies' => Currency::query()->active()->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    #[Middleware('permission:catalog.price-lists.create')]
    public function store(StorePriceListRequest $request, SavePriceList $savePriceList): RedirectResponse
    {
        $savePriceList->handle($request->validated());

        return redirect()->route('catalog.price-lists.index')->with('success', 'Lista de precios creada');
    }

    #[Middleware('permission:catalog.price-lists.update')]
    public function edit(PriceList $priceList): Response
    {
        return Inertia::render('catalog/price-lists/form', [
            'priceList' => $priceList,
            'history' => AuditHistory::for($priceList),
            'currencies' => Currency::query()->active()->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    #[Middleware('permission:catalog.price-lists.update')]
    public function update(UpdatePriceListRequest $request, PriceList $priceList, SavePriceList $savePriceList): RedirectResponse
    {
        $savePriceList->handle($request->validated(), $priceList);

        return redirect()->route('catalog.price-lists.index')->with('success', 'Lista de precios actualizada');
    }

    #[Middleware('permission:catalog.price-lists.delete')]
    public function destroy(PriceList $priceList): RedirectResponse
    {
        try {
            DB::transaction(function () use ($priceList): void {
                $priceList->items()->get()->each->delete();
                $priceList->delete();
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23503') {
                return back()->with('error', 'No se puede eliminar: el registro está en uso');
            }
            throw $e;
        }

        return redirect()->route('catalog.price-lists.index')->with('success', 'Lista de precios eliminada');
    }

    #[Middleware('permission:catalog.price-lists.export')]
    public function export(Request $request): BinaryFileResponse
    {
        $query = TableQuery::applyFilters(PriceList::query()->with('currency:id,code'), $request, ['name'], ['name', 'is_default', 'is_active'], 'name', 'asc');

        return TableExport::fromQuery($query, 'price-lists', [
            'Nombre' => 'name',
            'Moneda' => 'currency.code',
            'Predeterminada' => fn (PriceList $l) => $l->is_default ? 'Sí' : 'No',
            'Activa' => fn (PriceList $l) => $l->is_active ? 'Sí' : 'No',
        ], $request->string('format', 'xlsx')->toString());
    }

    #[Middleware('permission:catalog.price-lists.update')]
    public function items(PriceList $priceList): Response
    {
        $prices = $priceList->items()->pluck('price', 'product_id');
        $products = Product::query()->active()->orderBy('name')->get(['id', 'sku', 'name'])
            ->map(fn (Product $p) => ['id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'price' => isset($prices[$p->id]) ? (string) $prices[$p->id] : null])->values()->all();

        return Inertia::render('catalog/price-lists/items', ['list' => $priceList->load('currency:id,code'), 'products' => $products]);
    }

    #[Middleware('permission:catalog.price-lists.update')]
    public function updateItems(UpdatePriceListItemsRequest $request, PriceList $priceList, SavePriceListItems $action): RedirectResponse
    {
        $action->handle($priceList, $request->validated('items'));

        return redirect()->route('catalog.price-lists.items', $priceList)->with('success', 'Precios guardados');
    }

    #[Middleware('permission:catalog.price-lists.export')]
    public function exportItems(Request $request, PriceList $priceList): BinaryFileResponse
    {
        $query = PriceListItem::query()->with('product:id,sku,name')->where('price_list_id', $priceList->id)->orderBy('id');

        return TableExport::fromQuery($query, 'price-list-items', ['SKU' => 'product.sku', 'Producto' => 'product.name', 'Precio' => 'price'], $request->string('format', 'xlsx')->toString());
    }
}
