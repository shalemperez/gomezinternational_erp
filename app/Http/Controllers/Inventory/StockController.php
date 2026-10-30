<?php

namespace App\Http\Controllers\Inventory;

use App\Exports\TableExport;
use App\Http\Controllers\Controller;
use App\Models\StockBalance;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Money\Decimal;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StockController extends Controller
{
    #[Middleware('permission:inventory.stock.view')]
    public function index(Request $request): Response
    {
        $canViewCosts = $this->actor($request)->can('inventory.stock.view-costs');
        $rows = TableQuery::apply($this->filteredQuery($request), $request, ['products.sku', 'products.name'], ['sku', 'name', 'quantity', 'available'], 'sku', 'asc')
            ->through(fn (StockBalance $balance) => $this->stockRow($balance, $canViewCosts));

        return Inertia::render('inventory/stock/index', [
            'rows' => $rows,
            'filters' => $request->only(['search', 'sort', 'dir', 'per_page', 'warehouse_id', 'low_only']),
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'canViewCosts' => $canViewCosts,
        ]);
    }

    #[Middleware('permission:inventory.stock.export')]
    public function export(Request $request): BinaryFileResponse
    {
        $canViewCosts = $this->actor($request)->can('inventory.stock.view-costs');
        $query = TableQuery::applyFilters($this->filteredQuery($request), $request, ['products.sku', 'products.name'], ['sku', 'name', 'quantity', 'available'], 'sku', 'asc');

        $columns = [
            'SKU' => 'sku',
            'Producto' => 'name',
            'Almacén' => 'warehouse_name',
            'Existencia' => 'quantity',
            'Reservado' => 'reserved_quantity',
            'Disponible' => 'available',
            'Mínimo' => 'minimum_stock',
        ];
        if ($canViewCosts) {
            $columns['Costo promedio'] = 'average_cost';
            $columns['Valor'] = fn (StockBalance $balance) => Decimal::mul($balance->quantity, $balance->average_cost, Decimal::MONEY);
        }

        return TableExport::fromQuery($query, 'stock', $columns, $request->string('format', 'xlsx')->toString());
    }

    /** @return Builder<StockBalance> */
    private function filteredQuery(Request $request): Builder
    {
        $warehouseId = $request->integer('warehouse_id');
        $lowOnly = $request->boolean('low_only');

        return StockBalance::query()
            ->join('products', 'products.id', '=', 'stock_balances.product_id')
            ->join('warehouses', 'warehouses.id', '=', 'stock_balances.warehouse_id')
            ->whereNull('stock_balances.lot_id')
            ->select('stock_balances.*', 'products.sku', 'products.name', 'products.minimum_stock', 'warehouses.name as warehouse_name')
            ->addSelect(DB::raw('stock_balances.quantity - stock_balances.reserved_quantity as available'))
            ->when($warehouseId > 0, fn (Builder $query) => $query->where('stock_balances.warehouse_id', $warehouseId))
            ->when($lowOnly, fn (Builder $query) => $query->where('products.minimum_stock', '>', 0)->whereColumn('stock_balances.quantity', '<', 'products.minimum_stock'));
    }

    /** @return array<string, mixed> */
    private function stockRow(StockBalance $balance, bool $canViewCosts): array
    {
        $quantity = (string) $balance->quantity;
        $minimumStock = (string) $balance->getAttribute('minimum_stock');
        $row = [
            'id' => $balance->id,
            'sku' => $balance->getAttribute('sku'),
            'name' => $balance->getAttribute('name'),
            'warehouse' => $balance->getAttribute('warehouse_name'),
            'quantity' => $quantity,
            'reserved_quantity' => (string) $balance->reserved_quantity,
            'available' => $balance->available,
            'minimum_stock' => $minimumStock,
            'is_low' => Decimal::cmp($quantity, $minimumStock) < 0 && Decimal::cmp($minimumStock, '0') > 0,
        ];
        if ($canViewCosts) {
            $row['average_cost'] = (string) $balance->average_cost;
            $row['stock_value'] = Decimal::mul($quantity, (string) $balance->average_cost, Decimal::MONEY);
        }

        return $row;
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
