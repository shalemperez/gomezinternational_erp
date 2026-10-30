<?php

namespace App\Support\Ecf;

use Carbon\CarbonImmutable;

final readonly class EcfReference
{
    public function __construct(
        public string $ncfModificado,
        public CarbonImmutable $fechaNcfModificado,
        public int $codigoModificacion,
        public ?string $razonModificacion,
    ) {}
}
