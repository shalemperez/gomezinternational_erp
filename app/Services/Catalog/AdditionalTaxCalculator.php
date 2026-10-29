<?php

namespace App\Services\Catalog;

use App\Enums\AdditionalTaxCalculationType;
use App\Models\AdditionalTax;
use App\Support\Money\Decimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

class AdditionalTaxCalculator
{
    /** ARCH §4: per-line additional tax amount, 2 decimals HALF_UP. */
    public function forLine(AdditionalTax $tax, string $qty, string $taxableBase, ?string $volumeLiters, ?string $alcoholDegree): string
    {
        $type = $tax->calculation_type;

        return match ($type) {
            AdditionalTaxCalculationType::PercentOfBase => Decimal::percent($taxableBase, (string) $tax->rate),
            AdditionalTaxCalculationType::FixedPerUnit => Decimal::mul($qty, (string) $tax->amount, Decimal::MONEY),
            AdditionalTaxCalculationType::FixedPerLiter => Decimal::money(
                Decimal::of($qty)->multipliedBy(Decimal::of($this->requireVolume($volumeLiters)))->multipliedBy(Decimal::of((string) $tax->amount))
            ),
            AdditionalTaxCalculationType::FixedPerDegreeLiter => Decimal::money(
                Decimal::of($qty)
                    ->multipliedBy(Decimal::of($this->requireVolume($volumeLiters)))
                    ->multipliedBy(Decimal::of($this->requireDegree($alcoholDegree)))
                    ->dividedBy(100, 10, RoundingMode::HalfUp)
                    ->multipliedBy(Decimal::of((string) $tax->amount))
            ),
        };
    }

    private function requireVolume(?string $volumeLiters): string
    {
        if ($volumeLiters === null || $volumeLiters === '' || Decimal::isZero($volumeLiters)) {
            throw new InvalidArgumentException('El producto no tiene volumen en litros');
        }

        return $volumeLiters;
    }

    private function requireDegree(?string $alcoholDegree): string
    {
        if ($alcoholDegree === null || $alcoholDegree === '' || Decimal::isZero($alcoholDegree)) {
            throw new InvalidArgumentException('El producto no tiene grado alcohólico');
        }

        return $alcoholDegree;
    }
}
