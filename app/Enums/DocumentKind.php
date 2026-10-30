<?php

namespace App\Enums;

enum DocumentKind: string
{
    case Proforma = 'proforma';
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';
    case DebitNote = 'debit_note';
    case Receipt = 'receipt';
    case Dispatch = 'dispatch';

    public function prefix(): string
    {
        return match ($this) {
            self::Proforma => 'COT', self::Invoice => 'FAC', self::CreditNote => 'NC',
            self::DebitNote => 'ND', self::Receipt => 'REC', self::Dispatch => 'CND',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Proforma => 'Proforma', self::Invoice => 'Factura', self::CreditNote => 'Nota de crédito',
            self::DebitNote => 'Nota de débito', self::Receipt => 'Recibo', self::Dispatch => 'Conduce',
        };
    }
}
