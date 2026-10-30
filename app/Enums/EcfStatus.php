<?php

namespace App\Enums;

enum EcfStatus: string
{
    case Pending = 'pending';
    case Signed = 'signed';
    case Sent = 'sent';
    case InProcess = 'in_process';
    case Accepted = 'accepted';
    case ConditionallyAccepted = 'conditionally_accepted';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente de firma', self::Signed => 'Firmado, pendiente de envío', self::Sent => 'Enviado a la DGII',
            self::InProcess => 'En proceso en la DGII', self::Accepted => 'Aceptado', self::ConditionallyAccepted => 'Aceptado condicional',
            self::Rejected => 'Rechazado', self::Cancelled => 'Anulado sin enviar',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Accepted, self::ConditionallyAccepted, self::Rejected, self::Cancelled], true);
    }

    public function isValidForTax(): bool
    {
        return in_array($this, [self::Accepted, self::ConditionallyAccepted], true);
    }

    /** Estado codes returned by the DGII (`estado`): 0 Sent, 1 Accepted, 2 Rejected, 3 In process, 4 Conditionally accepted. */
    public static function fromDgiiCode(int $code): self
    {
        return match ($code) {
            0 => self::Sent, 1 => self::Accepted, 2 => self::Rejected, 3 => self::InProcess, 4 => self::ConditionallyAccepted,
            default => throw new \InvalidArgumentException("Código de estado DGII desconocido: {$code}"),
        };
    }
}
