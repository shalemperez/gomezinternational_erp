<?php

namespace App\Support\Ecf;

use Carbon\CarbonImmutable;

final readonly class SignedXml
{
    public function __construct(
        public string $xml,
        public string $signatureValue,
        public string $securityCode,
        public CarbonImmutable $signedAt,
    ) {}
}
