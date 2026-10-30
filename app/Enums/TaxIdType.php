<?php

namespace App\Enums;

enum TaxIdType: string
{
    case Rnc = 'rnc';
    case Cedula = 'cedula';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Rnc => 'RNC',
            self::Cedula => 'Cédula',
            self::None => 'Sin identificación',
        };
    }
}
