<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\StockBalance;
use App\Models\User;
use App\Notifications\StockAlert;
use App\Services\Inventory\InventoryService;
use App\Services\Settings;
use App\Support\Money\Decimal;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Notification;

#[Tries(1)]
class CheckStockAlerts implements ShouldQueue
{
    use Queueable;

    public function handle(InventoryService $inventory, Settings $settings): void
    {
        $lowStock = array_values($inventory->lowStock()->map(fn (Product $product) => [
            'sku' => $product->sku,
            'name' => $product->name,
            'quantity' => Decimal::qty((string) ($product->stock_total ?? '0')),
            'minimum_stock' => (string) $product->minimum_stock,
        ])->all());
        $expiringLots = array_values($inventory->expiringLots((int) $settings->get('inventory.expiring_days', 30))->map(fn (StockBalance $balance) => [
            'sku' => $balance->product->sku,
            'code' => $balance->lot->code,
            'expires_on' => $balance->lot->expires_on?->toDateString(),
            'quantity' => (string) $balance->quantity,
        ])->all());

        activity('inventory')->event('alerts_checked')->withProperties(['low_stock' => count($lowStock), 'expiring_lots' => count($expiringLots)])
            ->log('Revisión de alertas de inventario: '.count($lowStock).' bajo mínimo, '.count($expiringLots).' lotes por vencer');
        if ($lowStock === [] && $expiringLots === []) {
            return;
        }

        $users = User::query()->where('is_active', true)->get()->filter(fn (User $user) => $user->can('inventory.alerts.receive'));
        Notification::send($users, new StockAlert($lowStock, $expiringLots));
    }
}
