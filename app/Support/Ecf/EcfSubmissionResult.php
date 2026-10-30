<?php

namespace App\Support\Ecf;

use App\Enums\EcfStatus;
use Carbon\CarbonImmutable;

final readonly class EcfSubmissionResult
{
    /**
     * @param  list<array{codigo: string, valor: string}>  $messages
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public EcfStatus $status,
        public ?string $trackId,
        public ?int $code,
        public array $messages,
        public array $raw,
        public ?bool $sequenceUsed,
        public ?int $httpStatus,
        public ?string $securityCode = null,
        public ?CarbonImmutable $signedAt = null,
        public ?string $qrUrl = null,
        public ?string $signedXml = null,
    ) {}

    public function firstMessage(): ?string
    {
        return $this->messages[0]['valor'] ?? null;
    }
}
