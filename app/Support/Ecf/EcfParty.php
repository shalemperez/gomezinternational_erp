<?php

namespace App\Support\Ecf;

final readonly class EcfParty
{
    public function __construct(
        public string $rnc,
        public string $razonSocial,
        public ?string $nombreComercial = null,
        public ?string $direccion = null,
        public ?string $municipio = null,
        public ?string $provincia = null,
        public ?string $correo = null,
    ) {}
}
