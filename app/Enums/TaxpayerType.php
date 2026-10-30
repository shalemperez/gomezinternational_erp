<?php

namespace App\Enums;

enum TaxpayerType: string
{
    case Juridica = 'juridica';
    case Fisica = 'fisica';
    case ConsumidorFinal = 'consumidor_final';
    case Gubernamental = 'gubernamental';
    case RegimenEspecial = 'regimen_especial';
    case Extranjero = 'extranjero';

    public function label(): string
    {
        return match ($this) {
            self::Juridica => 'Persona jurídica',
            self::Fisica => 'Persona física',
            self::ConsumidorFinal => 'Consumidor final',
            self::Gubernamental => 'Gubernamental',
            self::RegimenEspecial => 'Régimen especial',
            self::Extranjero => 'Extranjero',
        };
    }
}
