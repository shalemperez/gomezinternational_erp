<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class NcfSequenceAlert extends Notification
{
    use Queueable;

    /** @param  list<array{ncf_type: string, label: string, remaining: int, expires_on: ?string, reason: string}>  $alerts */
    public function __construct(public readonly array $alerts) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)->subject('Alerta de secuencias NCF');
        foreach ($this->alerts as $alert) {
            $message->line($this->line($alert));
        }

        return $message->action('Ver secuencias', route('admin.ncf-sequences.index'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['alerts' => $this->alerts];
    }

    /** @param  array{ncf_type: string, label: string, remaining: int, expires_on: ?string, reason: string}  $alert */
    private function line(array $alert): string
    {
        $expiresOn = $alert['expires_on'] !== null ? Carbon::parse($alert['expires_on'])->format('d/m/Y') : null;

        return match ($alert['reason']) {
            'low' => "{$alert['label']} ({$alert['ncf_type']}): quedan {$alert['remaining']} números.",
            'expiring' => "{$alert['label']} ({$alert['ncf_type']}): vence el {$expiresOn}.",
            'expired' => "{$alert['label']} ({$alert['ncf_type']}): venció el {$expiresOn}.",
            default => "{$alert['label']} ({$alert['ncf_type']}): no hay secuencia registrada.",
        };
    }
}
