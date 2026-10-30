<?php

namespace App\Support\Ecf;

use App\Enums\NcfType;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;

final readonly class EcfDocumentData
{
    /**
     * @param  list<EcfItem>  $items
     */
    public function __construct(
        public NcfType $type,
        public string $encf,
        public CarbonImmutable $fechaEmision,
        public ?CarbonImmutable $fechaVencimientoSecuencia,
        public int $tipoPago,
        public ?CarbonImmutable $fechaLimitePago,
        public ?string $terminoPago,
        public EcfParty $emisor,
        public EcfParty $comprador,
        public array $items,
        public EcfTotals $totals,
        public ?EcfForeignCurrency $otraMoneda,
        public ?EcfReference $referencia,
        public ?int $indicadorNotaCredito,
        public ?string $numeroFacturaInterna,
        public ?string $codigoVendedor,
    ) {}

    public function isConsumo(): bool
    {
        return $this->type === NcfType::E32;
    }

    public function requiresRfce(string $threshold): bool
    {
        return $this->isConsumo() && Decimal::cmp($this->totals->montoTotal, $threshold) < 0;
    }

    public function fileName(): string
    {
        return $this->emisor->rnc.$this->encf.'.xml';
    }
}
