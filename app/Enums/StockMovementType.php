<?php

namespace App\Enums;

enum StockMovementType: string
{
    case Initial = 'initial';
    case Receipt = 'receipt';
    case Dispatch = 'dispatch';
    case Return = 'return';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';

    public function label(): string
    {
        return match ($this) {
            self::Initial => 'Existencia inicial',
            self::Receipt => 'Entrada',
            self::Dispatch => 'Salida por despacho',
            self::Return => 'Devolución',
            self::AdjustmentIn => 'Ajuste de entrada',
            self::AdjustmentOut => 'Ajuste de salida',
            self::TransferIn => 'Transferencia entrada',
            self::TransferOut => 'Transferencia salida',
        };
    }

    /** +1 for entries, -1 for exits. */
    public function direction(): int
    {
        return match ($this) {
            self::Initial, self::Receipt, self::Return, self::AdjustmentIn, self::TransferIn => 1,
            default => -1,
        };
    }
}
