<?php

namespace App\Enums;

enum ImportBatchStatus: string
{
    case Previewed = 'previewed';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Previewed => 'Vista previa',
            self::Confirmed => 'Importado',
            self::Cancelled => 'Cancelado',
        };
    }
}
