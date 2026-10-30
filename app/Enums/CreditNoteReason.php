<?php

namespace App\Enums;

enum CreditNoteReason: string
{
    case Return = 'return';
    case Correction = 'correction';
    case Discount = 'discount';

    public function label(): string
    {
        return match ($this) {
            self::Return => 'Devolución de mercancía', self::Correction => 'Corrección / anulación parcial',
            self::Discount => 'Descuento posterior',
        };
    }
}
