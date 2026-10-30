<?php

namespace App\Actions\Mail;

use App\Contracts\GmailSender;
use App\Exceptions\GmailNotConnectedException;
use App\Models\SentEmail;
use App\Models\User;
use App\Services\Mail\EmailTemplateRenderer;
use App\Support\Mail\OutgoingEmail;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class SendDocumentEmail
{
    public function __construct(private readonly GmailSender $sender, private readonly EmailTemplateRenderer $renderer) {}

    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     * @param  array<string, string>  $vars
     * @param  list<array{name: string, mime: string, content: string}>  $attachments
     */
    public function handle(User $user, Model $document, string $templateKey, array $to, array $cc, array $vars, array $attachments, ?string $customBody = null, ?string $customSubject = null): SentEmail
    {
        if ($user->gmail_connected_at === null) {
            throw new GmailNotConnectedException;
        }
        $rendered = $this->renderer->render($templateKey, $vars);
        $subject = $customSubject ?: $rendered['subject'];
        $body = $customBody ?: $rendered['body_html'];
        $record = SentEmail::create([
            'user_id' => $user->id, 'mailable_type' => $document::class, 'mailable_id' => $document->getKey(), 'template_key' => $templateKey,
            'to' => $to, 'cc' => $cc, 'subject' => $subject, 'body_html' => $body, 'attachments' => array_column($attachments, 'name'), 'status' => 'pending',
        ]);
        try {
            $result = $this->sender->send($user, new OutgoingEmail($to, $cc, $subject, $body, $attachments));
            $record->update(['status' => 'sent', 'gmail_message_id' => $result->messageId, 'gmail_thread_id' => $result->threadId, 'sent_at' => now()]);
            activity($document->getTable())->causedBy($user)->performedOn($document)->event('emailed')->withProperties(['to' => $to, 'cc' => $cc, 'subject' => $subject, 'sent_email_id' => $record->id])->log('Enviado por correo a '.implode(', ', $to));
        } catch (Throwable $e) {
            $record->update(['status' => 'failed', 'error' => $e->getMessage()]);
            activity($document->getTable())->causedBy($user)->performedOn($document)->event('email_failed')->withProperties(['to' => $to, 'error' => $e->getMessage()])->log('Falló el envío por correo');
            throw $e;
        }

        return $record->fresh();
    }
}
