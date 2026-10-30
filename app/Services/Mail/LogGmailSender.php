<?php

namespace App\Services\Mail;

use App\Contracts\GmailSender;
use App\Models\User;
use App\Support\Mail\OutgoingEmail;
use App\Support\Mail\SentEmailResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LogGmailSender implements GmailSender
{
    public function send(User $sender, OutgoingEmail $email): SentEmailResult
    {
        $id = 'log-'.Str::uuid();
        Log::channel('mail')->info('Gmail (log driver) message', ['id' => $id, 'from' => $sender->gmail_email ?? $sender->email, 'to' => $email->to, 'cc' => $email->cc, 'subject' => $email->subject, 'attachments' => array_column($email->attachments, 'name')]);

        return new SentEmailResult($id, null);
    }
}
