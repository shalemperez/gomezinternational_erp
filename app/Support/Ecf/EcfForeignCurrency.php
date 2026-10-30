<?php

namespace App\Support\Ecf;

final readonly class EcfForeignCurrency
{
    public function __construct(
        public string $tipoMoneda,
        public string $tipoCambio,
        public EcfTotals $totals,
    ) {}
}
