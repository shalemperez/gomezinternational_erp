<?php

namespace App\Support\Ecf;

final readonly class EcfItem
{
    /** @param  list<string>  $impuestosAdicionales  Tabla I codes */
    public function __construct(
        public int $numeroLinea,
        public int $indicadorFacturacion,
        public string $nombre,
        public ?string $descripcion,
        public string $cantidad,
        public int $unidadMedida,
        public string $precioUnitario,
        public string $descuentoMonto,
        public array $impuestosAdicionales,
        public string $montoItem,
        public string $itbisAmount,
    ) {}
}
