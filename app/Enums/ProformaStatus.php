<?php

namespace App\Enums;

enum ProformaStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Invoiced = 'invoiced';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador', self::Sent => 'Enviada', self::Approved => 'Aprobada',
            self::Rejected => 'Rechazada', self::Expired => 'Vencida', self::Invoiced => 'Facturada',
        };
    }
}
