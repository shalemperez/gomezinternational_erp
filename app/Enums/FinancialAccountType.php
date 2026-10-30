<?php

namespace App\Enums;

enum FinancialAccountType: string
{
    case Bank = 'bank';
    case Cash = 'cash';

    public function label(): string
    {
        return match ($this) {
            self::Bank => 'Cuenta bancaria',
            self::Cash => 'Caja',
        };
    }
}
