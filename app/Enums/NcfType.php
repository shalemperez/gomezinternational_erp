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
    case E31 = 'E31';
    case E32 = 'E32';
    case E33 = 'E33';
    case E34 = 'E34';
    case E41 = 'E41';
    case E43 = 'E43';
    case E44 = 'E44';
    case E45 = 'E45';
    case E46 = 'E46';
    case E47 = 'E47';

    public function label(): string
    {
        return match ($this) {
            self::B01 => 'Factura de Crédito Fiscal', self::B02 => 'Factura de Consumo', self::B03 => 'Nota de Débito', self::B04 => 'Nota de Crédito',
            self::B11 => 'Comprobante de Compras', self::B13 => 'Gastos Menores', self::B14 => 'Regímenes Especiales', self::B15 => 'Gubernamental',
            self::B16 => 'Exportaciones', self::B17 => 'Pagos al Exterior',
            self::E31 => 'Factura de Crédito Fiscal Electrónica', self::E32 => 'Factura de Consumo Electrónica', self::E33 => 'Nota de Débito Electrónica',
            self::E34 => 'Nota de Crédito Electrónica', self::E41 => 'Comprobante Electrónico de Compras', self::E43 => 'Comprobante Electrónico para Gastos Menores',
            self::E44 => 'Comprobante Electrónico para Regímenes Especiales', self::E45 => 'Comprobante Electrónico Gubernamental',
            self::E46 => 'Comprobante Electrónico para Exportaciones', self::E47 => 'Comprobante Electrónico para Pagos al Exterior',
        };
    }

    public function isElectronic(): bool
    {
        return str_starts_with($this->value, 'E');
    }

    /** Two-digit e-CF type code used in the XML (`TipoeCF`); null for traditional B-series types. */
    public function ecfCode(): ?string
    {
        return $this->isElectronic() ? substr($this->value, 1) : null;
    }

    /** The e-CF type that replaces this traditional type in ecf mode (an e-CF type maps to itself). */
    public function electronicCounterpart(): self
    {
        return match ($this) {
            self::B01 => self::E31, self::B02 => self::E32, self::B03 => self::E33, self::B04 => self::E34,
            self::B11 => self::E41, self::B13 => self::E43, self::B14 => self::E44, self::B15 => self::E45,
            self::B16 => self::E46, self::B17 => self::E47,
            self::E31, self::E32, self::E33, self::E34, self::E41, self::E43, self::E44, self::E45, self::E46, self::E47 => $this,
        };
    }

    /** Norma General 06-2018 art. 6 / Guía NCF §6: consumo and notas de crédito do not expire (also E32 and E34). */
    public function expires(): bool
    {
        return ! in_array($this, [self::B02, self::B04, self::E32, self::E34], true);
    }

    public function isSalesType(): bool
    {
        return in_array($this, [
            self::B01, self::B02, self::B03, self::B04, self::B14, self::B15, self::B16,
            self::E31, self::E32, self::E33, self::E34, self::E44, self::E45, self::E46,
        ], true);
    }

    /** Digits of the sequential part of the NCF (B: 8, E: 10). */
    public function sequenceDigits(): int
    {
        return $this->isElectronic() ? 10 : 8;
    }

    public function maxSequence(): int
    {
        return $this->isElectronic() ? 9999999999 : 99999999;
    }
}
