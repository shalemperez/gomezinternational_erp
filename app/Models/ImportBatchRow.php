<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bulk-inserted staging rows; the owning batch carries the audit trail (ARCH §6.1 exception).
 *
 * @property array<string, mixed> $data
 * @property array<string, string>|null $errors
 * @property bool $is_valid
 */
class ImportBatchRow extends Model
{
    public $timestamps = false;

    protected $fillable = ['import_batch_id', 'row_number', 'data', 'errors', 'is_valid', 'action'];

    protected function casts(): array
    {
        return ['data' => 'array', 'errors' => 'array', 'is_valid' => 'boolean'];
    }

    /** @return BelongsTo<ImportBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }
}
