<?php

namespace App\Enums;

enum ExchangeRateSourceType: string
{
    case Manual = 'manual';
    case HttpJson = 'http_json';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::HttpJson => 'Servicio externo (HTTP JSON)',
        };
    }
}
