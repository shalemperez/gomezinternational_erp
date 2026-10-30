<?php

namespace App\Enums;

enum AdditionalTaxCalculationType: string
{
    case PercentOfBase = 'percent_of_base';
    case FixedPerUnit = 'fixed_per_unit';
    case FixedPerLiter = 'fixed_per_liter';
    case FixedPerDegreeLiter = 'fixed_per_degree_liter';

    public function label(): string
    {
        return match ($this) {
            self::PercentOfBase => 'Porcentaje sobre la base (ad valorem)',
            self::FixedPerUnit => 'Monto fijo por unidad',
            self::FixedPerLiter => 'Monto fijo por litro',
            self::FixedPerDegreeLiter => 'Monto fijo por litro de alcohol puro',
        };
    }
}
