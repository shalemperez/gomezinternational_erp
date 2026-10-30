<?php

namespace App\Support\Mail;

final readonly class OutgoingEmail
{
    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     * @param  list<array{name: string, mime: string, content: string}>  $attachments
     */
    public function __construct(public array $to, public array $cc, public string $subject, public string $bodyHtml, public array $attachments = []) {}
}
