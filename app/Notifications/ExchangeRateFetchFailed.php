<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ExchangeRateFetchFailed extends Notification
{
    use Queueable;

    public function __construct(public readonly string $message) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('No se pudo obtener la tasa de cambio')
            ->line("La obtención automática de la tasa de cambio de hoy falló: {$this->message}")
            ->line('Registra la tasa manualmente en Finanzas → Monedas y tasas.')
            ->action('Registrar tasa', route('finance.exchange-rates.index'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['message' => $this->message];
    }
}
