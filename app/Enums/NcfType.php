<?php

namespace App\Enums;

enum NcfType: string
{
    case B01 = 'B01';
    case B02 = 'B02';
    case B03 = 'B03';
    case B04 = 'B04';
    case B11 = 'B11';
    case B13 = 'B13';
    case B14 = 'B14';
    case B15 = 'B15';
    case B16 = 'B16';
    case B17 = 'B17';

    public function label(): string
    {
        return match ($this) {
            self::B01 => 'Factura de Crédito Fiscal', self::B02 => 'Factura de Consumo', self::B03 => 'Nota de Débito', self::B04 => 'Nota de Crédito',
            self::B11 => 'Comprobante de Compras', self::B13 => 'Gastos Menores', self::B14 => 'Regímenes Especiales', self::B15 => 'Gubernamental',
            self::B16 => 'Exportaciones', self::B17 => 'Pagos al Exterior',
        };
    }

    /** Norma General 06-2018 art. 6 / Guía NCF §6: consumo and notas de crédito do not expire. */
    public function expires(): bool
    {
        return ! in_array($this, [self::B02, self::B04], true);
    }

    public function isSalesType(): bool
    {
        return in_array($this, [self::B01, self::B02, self::B03, self::B04, self::B14, self::B15, self::B16], true);
    }
}
