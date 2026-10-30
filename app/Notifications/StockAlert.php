<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class StockAlert extends Notification
{
    use Queueable;

    /**
     * @param  list<array{sku: string, name: string, quantity: string, minimum_stock: string}>  $lowStock
     * @param  list<array{sku: string, code: string, expires_on: ?string, quantity: string}>  $expiringLots
     */
    public function __construct(public readonly array $lowStock, public readonly array $expiringLots) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)->subject('Alertas de inventario');
        foreach ($this->lowStock as $item) {
            $message->line("Stock bajo mínimo: {$item['sku']} {$item['name']} — existencia {$item['quantity']}, mínimo {$item['minimum_stock']}");
        }
        foreach ($this->expiringLots as $lot) {
            $expiresOn = $lot['expires_on'] !== null ? Carbon::parse($lot['expires_on'])->format('d/m/Y') : '';
            $message->line("Lote por vencer: {$lot['sku']} lote {$lot['code']} vence el {$expiresOn} ({$lot['quantity']} unidades)");
        }

        return $message->action('Ver existencias', route('inventory.stock.index'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['low_stock' => $this->lowStock, 'expiring_lots' => $this->expiringLots];
    }
}
