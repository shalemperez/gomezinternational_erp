<?php

namespace App\Support\Ecf;

final readonly class EcfTotals
{
    /** @param  list<EcfAdditionalTax>  $impuestosAdicionales */
    public function __construct(
        public ?string $montoGravadoTotal,
        public ?string $montoGravadoI1,
        public ?string $montoGravadoI2,
        public ?string $montoGravadoI3,
        public ?string $montoExento,
        public ?string $totalItbis,
        public ?string $totalItbis1,
        public ?string $totalItbis2,
        public ?string $totalItbis3,
        public ?string $montoImpuestoAdicional,
        public array $impuestosAdicionales,
        public string $montoTotal,
    ) {}
}
