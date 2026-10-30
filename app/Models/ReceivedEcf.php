<?php

namespace App\Models;

use App\Models\Concerns\LogsModelActivity;
use Carbon\CarbonInterface;
use Database\Factories\ReceivedEcfFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An e-CF that a supplier sent to the company (receiver role). arecf_status 0 = recibido, 1 = no recibido.
 *
 * @property string $rnc_emisor
 * @property string|null $razon_social_emisor
 * @property string $ncf_type
 * @property string $encf
 * @property string|null $rnc_comprador
 * @property CarbonInterface|null $fecha_emision
 * @property string $monto_total
 * @property string $total_itbis
 * @property string $xml_path
 * @property string|null $arecf_path
 * @property int $arecf_status
 * @property int|null $arecf_reason_code
 * @property CarbonInterface $received_at
 * @property string|null $sender_ip
 * @property int|null $supplier_id
 * @property string $commercial_status
 * @property string|null $commercial_reason
 * @property string|null $acecf_path
 * @property CarbonInterface|null $commercial_sent_at
 * @property int|null $commercial_by
 * @property string|null $notes
 */
class ReceivedEcf extends Model
{
    /** @use HasFactory<ReceivedEcfFactory> */
    use HasFactory, LogsModelActivity;

    protected $fillable = [
        'rnc_emisor', 'razon_social_emisor', 'ncf_type', 'encf', 'rnc_comprador', 'fecha_emision', 'monto_total', 'total_itbis', 'xml_path',
        'arecf_path', 'arecf_status', 'arecf_reason_code', 'received_at', 'sender_ip', 'supplier_id', 'commercial_status', 'commercial_reason',
        'acecf_path', 'commercial_sent_at', 'commercial_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'fecha_emision' => 'date', 'monto_total' => 'decimal:2', 'total_itbis' => 'decimal:2', 'arecf_status' => 'integer',
            'arecf_reason_code' => 'integer', 'received_at' => 'datetime', 'commercial_sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'commercial_by');
    }

    public function statusLabel(): string
    {
        return $this->arecf_status === 0 ? 'Recibido' : 'No recibido';
    }

    public function commercialStatusLabel(): string
    {
        return match ($this->commercial_status) {
            'approved' => 'Aprobado',
            'rejected' => 'Rechazado',
            default => 'Pendiente',
        };
    }
}
