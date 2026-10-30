<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserInvited extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail']; // deliberate: the invitee has no session, so no database channel (ARCH §7 exception)
    }

    /** @param User $notifiable */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Invitación a Gomez ERP')
            ->greeting("Hola, {$notifiable->name}")
            ->line('Te han invitado a usar Gomez ERP. Inicia sesión con tu cuenta de Google Workspace de la empresa.')
            ->action('Iniciar sesión', route('login'))
            ->salutation('Gomez ERP');
    }
}
