<?php

namespace App\Services\Mail;

use App\Models\EmailTemplate;

class EmailTemplateRenderer
{
    private const PLACEHOLDER = '/\{\{\s*([a-z_]+)\s*\}\}/';

    /**
     * @param  array<string, string>  $vars
     * @return array{subject: string, body_html: string}
     */
    public function render(string $key, array $vars): array
    {
        $template = EmailTemplate::query()->where('key', $key)->firstOrFail();

        return [
            'subject' => (string) preg_replace_callback(self::PLACEHOLDER, fn (array $match): string => strip_tags((string) ($vars[$match[1]] ?? '')), $template->subject),
            'body_html' => (string) preg_replace_callback(self::PLACEHOLDER, fn (array $match): string => e((string) ($vars[$match[1]] ?? '')), $template->body_html),
        ];
    }
}
