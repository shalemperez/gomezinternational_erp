<?php

namespace App\Enums;

enum InvoiceKind: string
{
    case Sale = 'sale';
    case Historical = 'historical';

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Venta', self::Historical => 'Histórica',
        };
    }
}
