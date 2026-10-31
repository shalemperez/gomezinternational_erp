<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class EcfAlert extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<array{encf: string, number: string, created_at: string, urgent: bool}>  $pending
     * @param  list<array{encf: string, number: string, message: string}>  $rejected
     * @param  array{name: string, expires_on: string, days_left: int, expired: bool}|null  $certificate
     */
    public function __construct(public readonly array $pending, public readonly array $rejected, public readonly ?array $certificate = null)
    {
        $this->afterCommit();
    }

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
        if ($this->certificate !== null) {
            ['name' => $name, 'expires_on' => $expiresOn, 'days_left' => $daysLeft, 'expired' => $expired] = $this->certificate;
            $message->line($expired
                ? "El certificado digital {$name} venció el {$expiresOn}; la emisión electrónica está detenida hasta que cargues uno vigente."
                : "El certificado digital {$name} vence el {$expiresOn} (en {$daysLeft} días); carga uno nuevo en Configuración → Facturación electrónica.");
        }

        return $message;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['pending' => $this->pending, 'rejected' => $this->rejected, 'certificate' => $this->certificate];
    }
}
