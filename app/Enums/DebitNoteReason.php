<?php

namespace App\Enums;

enum DebitNoteReason: string
{
    case PriceCorrection = 'price_correction';
    case Interest = 'interest';
    case Freight = 'freight';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::PriceCorrection => 'Corrección de precio', self::Interest => 'Intereses por mora',
            self::Freight => 'Flete', self::Other => 'Otro cargo',
        };
    }
}
