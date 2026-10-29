<?php

namespace App\Enums;

enum ItbisRate: string
{
    case Standard = '18.00';
    case Reduced = '16.00';
    case Exempt = '0.00';

    public function label(): string
    {
        return match ($this) {
            self::Standard => '18 %',
            self::Reduced => '16 %',
            self::Exempt => 'Exento',
        };
    }
}
