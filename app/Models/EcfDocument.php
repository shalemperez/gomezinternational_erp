<?php

namespace App\Models;

use App\Enums\EcfEnvironment;
use App\Enums\EcfStatus;
use App\Enums\NcfType;
use App\Models\Concerns\LogsModelActivity;
use Carbon\CarbonInterface;
use Database\Factories\EcfDocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property string $document_type
 * @property int $document_id
 * @property NcfType $ncf_type
 * @property string $encf
 * @property EcfEnvironment $environment
 * @property string $provider
 * @property string $flow
 * @property EcfStatus $status
 * @property string|null $track_id
 * @property string|null $security_code
 * @property CarbonInterface|null $signed_at
 * @property string|null $xml_path
 * @property string|null $rfce_xml_path
 * @property int|null $dgii_code
 * @property array<int, array{codigo: string, valor: string}>|null $dgii_messages
 * @property int $attempts
 * @property CarbonInterface|null $last_attempt_at
 * @property CarbonInterface|null $next_attempt_at
 * @property CarbonInterface|null $accepted_at
 * @property CarbonInterface|null $rejected_at
 * @property string|null $last_error
 * @property string|null $commercial_status
 * @property string|null $commercial_reason
 * @property CarbonInterface|null $commercial_at
 * @property string|null $qr_url
 * @property int|null $ncf_sequence_id
 */
class EcfDocument extends Model
{
    /** @use HasFactory<EcfDocumentFactory> */
    use HasFactory, LogsModelActivity;

    protected $fillable = [
        'document_type', 'document_id', 'ncf_type', 'encf', 'environment', 'provider', 'flow', 'status', 'track_id', 'security_code', 'signed_at',
        'xml_path', 'rfce_xml_path', 'dgii_code', 'dgii_messages', 'attempts', 'last_attempt_at', 'next_attempt_at', 'accepted_at', 'rejected_at',
        'last_error', 'commercial_status', 'commercial_reason', 'commercial_at', 'qr_url', 'ncf_sequence_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => EcfStatus::class, 'ncf_type' => NcfType::class, 'environment' => EcfEnvironment::class, 'dgii_messages' => 'array',
            'signed_at' => 'datetime', 'last_attempt_at' => 'datetime', 'next_attempt_at' => 'datetime', 'accepted_at' => 'datetime',
            'rejected_at' => 'datetime', 'commercial_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function document(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<EcfTransmission, $this> */
    public function transmissions(): HasMany
    {
        return $this->hasMany(EcfTransmission::class)->latest('id');
    }

    /** @return BelongsTo<NcfSequence, $this> */
    public function sequence(): BelongsTo
    {
        return $this->belongsTo(NcfSequence::class, 'ncf_sequence_id');
    }

    /** @param  Builder<EcfDocument>  $query */
    public function scopeReadyToSubmit(Builder $query): void
    {
        $query->whereIn('status', [EcfStatus::Pending->value, EcfStatus::Signed->value])
            ->where(fn (Builder $due) => $due->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()));
    }

    /** @param  Builder<EcfDocument>  $query */
    public function scopeAwaitingResult(Builder $query): void
    {
        $query->whereIn('status', [EcfStatus::Sent->value, EcfStatus::InProcess->value]);
    }

    public function isRfce(): bool
    {
        return $this->flow === 'rfce';
    }

    /** A document that never reached the DGII (no attempt) may be cancelled locally; its e-NCF is then not reused. */
    public function canBeCancelledLocally(): bool
    {
        return in_array($this->status, [EcfStatus::Pending, EcfStatus::Signed], true) && $this->attempts === 0;
    }

    public function statusLabel(): string
    {
        return $this->status->label();
    }
}
