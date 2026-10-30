<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\StockBalance;
use App\Models\Warehouse;
use App\Services\Settings;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class LotController extends Controller
{
    #[Middleware('permission:inventory.lots.view')]
    public function index(Request $request, Settings $settings): Response
    {
        $expiringDays = (int) $settings->get('inventory.expiring_days', 30);
        $rows = TableQuery::apply($this->filteredQuery($request, $expiringDays), $request, ['products.sku', 'products.name'], ['sku', 'name', 'expires_on', 'quantity'], 'expires_on', 'asc')
            ->through(fn (StockBalance $balance) => $this->lotRow($balance, $expiringDays));

        return Inertia::render('inventory/lots/index', [
            'rows' => $rows,
            'filters' => $request->only(['search', 'sort', 'dir', 'per_page', 'warehouse_id', 'expiring_only']),
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'expiringDays' => $expiringDays,
        ]);
    }

    /** @return Builder<StockBalance> */
    private function filteredQuery(Request $request, int $expiringDays): Builder
    {
        $warehouseId = $request->integer('warehouse_id');
        $expiringOnly = $request->boolean('expiring_only');

        return StockBalance::query()
            ->join('products', 'products.id', '=', 'stock_balances.product_id')
            ->join('warehouses', 'warehouses.id', '=', 'stock_balances.warehouse_id')
            ->join('lots', 'lots.id', '=', 'stock_balances.lot_id')
            ->whereNotNull('stock_balances.lot_id')
            ->where('stock_balances.quantity', '>', 0)
            ->select('stock_balances.*', 'products.sku', 'products.name', 'lots.code as lot_code', 'lots.expires_on', 'warehouses.name as warehouse_name')
            ->when($warehouseId > 0, fn (Builder $query) => $query->where('stock_balances.warehouse_id', $warehouseId))
            ->when($expiringOnly, fn (Builder $query) => $query->whereNotNull('lots.expires_on')->whereDate('lots.expires_on', '<=', today()->addDays($expiringDays)->toDateString()));
    }

    /** @return array<string, mixed> */
    private function lotRow(StockBalance $balance, int $expiringDays): array
    {
        $expiresOn = $balance->getAttribute('expires_on') !== null ? Carbon::parse((string) $balance->getAttribute('expires_on'))->startOfDay() : null;
        $daysToExpiry = $expiresOn !== null ? (int) today()->diffInDays($expiresOn, false) : null;

        return [
            'id' => $balance->id,
            'sku' => $balance->getAttribute('sku'),
            'name' => $balance->getAttribute('name'),
            'lot_code' => $balance->getAttribute('lot_code'),
            'expires_on' => $expiresOn?->toDateString(),
            'warehouse' => $balance->getAttribute('warehouse_name'),
            'quantity' => (string) $balance->quantity,
            'days_to_expiry' => $daysToExpiry,
            'state' => match (true) {
                $daysToExpiry === null => 'ok',
                $daysToExpiry < 0 => 'expired',
                $daysToExpiry <= $expiringDays => 'expiring',
                default => 'ok',
            },
        ];
    }
}
