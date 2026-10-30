<?php

namespace App\Support\Fiscal;

use App\Enums\NcfType;
use Carbon\CarbonImmutable;

final readonly class FiscalNumber
{
    public function __construct(public string $ncf, public NcfType $type, public ?CarbonImmutable $expiresOn, public int $sequenceId) {}
}
