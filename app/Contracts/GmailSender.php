<?php

namespace App\Contracts;

use App\Models\User;
use App\Support\Mail\OutgoingEmail;
use App\Support\Mail\SentEmailResult;

interface GmailSender
{
    public function send(User $sender, OutgoingEmail $email): SentEmailResult;
}
