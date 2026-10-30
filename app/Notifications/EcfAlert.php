<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class EcfAlert extends Notification
{
    use Queueable;

    /**
     * @param  list<array{encf: string, number: string, created_at: string, urgent: bool}>  $pending
     * @param  list<array{encf: string, number: string, message: string}>  $rejected
     */
    public function __construct(public readonly array $pending, public readonly array $rejected) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)->subject('Alerta de comprobantes electrónicos');
        foreach ($this->pending as $item) {
            $since = Carbon::parse($item['created_at'])->setTimezone(config('ecf.timezone'))->format('d/m/Y H:i');
            $line = "{$item['encf']} ({$item['number']}) pendiente desde {$since}";
            $message->line($item['urgent'] ? $line.' — URGENTE: plazo DGII de 72 h' : $line);
        }
        foreach ($this->rejected as $item) {
            $message->line("{$item['encf']} ({$item['number']}) rechazado: {$item['message']}");
        }

        return $message;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['pending' => $this->pending, 'rejected' => $this->rejected];
    }
}
