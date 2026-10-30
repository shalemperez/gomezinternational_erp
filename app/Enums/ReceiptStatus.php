<?php

namespace App\Enums;

enum ReceiptStatus: string
{
    case Posted = 'posted';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Posted => 'Registrado', self::Cancelled => 'Anulado',
        };
    }
}
