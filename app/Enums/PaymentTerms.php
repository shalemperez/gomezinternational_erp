<?php

namespace App\Enums;

enum PaymentTerms: string
{
    case Cash = 'cash';
    case Credit = 'credit';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Contado', self::Credit => 'Crédito',
        };
    }
}
