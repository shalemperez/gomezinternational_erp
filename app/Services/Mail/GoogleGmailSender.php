<?php

namespace App\Services\Mail;

use App\Contracts\GmailSender;
use App\Exceptions\GmailNotConnectedException;
use App\Models\User;
use App\Support\Mail\OutgoingEmail;
use App\Support\Mail\SentEmailResult;
use Google\Client as GoogleClient;
use Google\Service\Gmail;
use Google\Service\Gmail\Message;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class GoogleGmailSender implements GmailSender
{
    public function send(User $sender, OutgoingEmail $email): SentEmailResult
    {
        if ($sender->gmail_refresh_token === null) {
            throw new GmailNotConnectedException;
        }
        $client = new GoogleClient;
        $client->setClientId(config('services.google.client_id'));
        $client->setClientSecret(config('services.google.client_secret'));
        $client->setAccessType('offline');
        $token = $client->fetchAccessTokenWithRefreshToken($sender->gmail_refresh_token);
        if (isset($token['error'])) {
            throw new \RuntimeException('Google rechazó el token de Gmail: '.$token['error']);
        }
        $client->setAccessToken($token);

        $mime = (new Email)->from(new Address($sender->gmail_email ?? $sender->email, $sender->name))->to(...$email->to)->subject($email->subject)->html($email->bodyHtml);
        if ($email->cc !== []) {
            $mime->cc(...$email->cc);
        }
        foreach ($email->attachments as $attachment) {
            $mime->attach($attachment['content'], $attachment['name'], $attachment['mime']);
        }
        $message = new Message;
        $message->setRaw(rtrim(strtr(base64_encode($mime->toString()), '+/', '-_'), '='));
        $sent = (new Gmail($client))->users_messages->send('me', $message);

        return new SentEmailResult((string) $sent->getId(), $sent->getThreadId());
    }
}
