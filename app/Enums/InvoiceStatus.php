<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador', self::Issued => 'Emitida', self::PartiallyPaid => 'Pagada parcialmente',
            self::Paid => 'Pagada', self::Cancelled => 'Anulada',
        };
    }
}
