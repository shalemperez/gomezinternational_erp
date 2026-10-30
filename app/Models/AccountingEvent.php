<?php

namespace App\Models;

use App\Models\Concerns\LogsModelActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AccountingEvent extends Model
{
    use LogsModelActivity;

    protected $fillable = ['type', 'document_type', 'document_id', 'occurred_on', 'currency_id', 'exchange_rate', 'payload', 'processed_at'];

    protected function casts(): array
    {
        return [
            'occurred_on' => 'date:Y-m-d',
            'exchange_rate' => 'decimal:6',
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function document(): MorphTo
    {
        return $this->morphTo('document', 'document_type', 'document_id');
    }

    /** @return BelongsTo<Currency, $this> */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
