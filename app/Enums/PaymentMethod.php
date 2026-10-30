<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Transfer = 'transfer';
    case Check = 'check';
    case Cash = 'cash';
    case Card = 'card';
    case Advance = 'advance';

    public function label(): string
    {
        return match ($this) {
            self::Transfer => 'Transferencia', self::Check => 'Cheque', self::Cash => 'Efectivo',
            self::Card => 'Tarjeta', self::Advance => 'Anticipo',
        };
    }
}
