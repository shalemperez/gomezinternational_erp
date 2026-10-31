<?php

namespace App\Notifications;

use App\Models\EcfDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EcfRejected extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly EcfDocument $document)
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
        return (new MailMessage)
            ->subject("e-CF rechazado por la DGII: {$this->document->encf}")
            ->line('Comprobante: '.$this->document->document?->getAttribute('number'))
            ->line("e-NCF: {$this->document->encf}")
            ->line('Mensaje de la DGII: '.($this->document->dgii_messages[0]['valor'] ?? ''))
            ->action('Ver e-CF', route('fiscal.ecf-documents.show', $this->document));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'ecf_document_id' => $this->document->id,
            'encf' => $this->document->encf,
            'message' => $this->document->dgii_messages[0]['valor'] ?? '',
        ];
    }
}
