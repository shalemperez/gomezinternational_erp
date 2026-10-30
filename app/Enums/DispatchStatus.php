<?php

namespace App\Enums;

enum DispatchStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador', self::Confirmed => 'Confirmado',
        };
    }
}
