<?php

namespace App\Support\Ecf;

final readonly class EcfAdditionalTax
{
    public function __construct(
        public string $tipoImpuesto,
        public string $tasa,
        public ?string $especifico,
        public ?string $advalorem,
        public ?string $otros,
    ) {}
}
