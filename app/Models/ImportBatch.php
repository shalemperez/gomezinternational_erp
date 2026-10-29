<?php

namespace App\Models;

use App\Enums\ImportBatchStatus;
use App\Models\Concerns\LogsModelActivity;
use Carbon\CarbonImmutable;
use Database\Factories\ImportBatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property ImportBatchStatus $status
 * @property array<string, mixed>|null $meta
 * @property CarbonImmutable|null $confirmed_at
 */
class ImportBatch extends Model
{
    /** @use HasFactory<ImportBatchFactory> */
    use HasFactory, LogsModelActivity;

    protected $fillable = [
        'type', 'file_path', 'original_name', 'status', 'total_rows', 'valid_rows', 'invalid_rows', 'created_count', 'updated_count',
        'meta', 'user_id', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return ['status' => ImportBatchStatus::class, 'meta' => 'array', 'confirmed_at' => 'datetime'];
    }

    /** @return HasMany<ImportBatchRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(ImportBatchRow::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
