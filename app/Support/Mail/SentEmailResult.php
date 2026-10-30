<?php

namespace App\Support\Mail;

final readonly class SentEmailResult
{
    public function __construct(public string $messageId, public ?string $threadId = null) {}
}
