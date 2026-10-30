<?php

namespace App\Models;

use App\Enums\ExchangeRateSourceType;
use App\Models\Concerns\LogsModelActivity;
use Database\Factories\ExchangeRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $rate_date
 * @property ExchangeRateSourceType $source
 * @property Carbon|null $fetched_at
 */
class ExchangeRate extends Model
{
    /** @use HasFactory<ExchangeRateFactory> */
    use HasFactory, LogsModelActivity;

    protected $fillable = ['currency_id', 'rate_date', 'rate', 'source', 'fetched_at', 'created_by', 'notes'];

    protected function casts(): array
    {
        return ['rate_date' => 'date:Y-m-d', 'rate' => 'decimal:6', 'fetched_at' => 'datetime', 'source' => ExchangeRateSourceType::class];
    }

    /** @return BelongsTo<Currency, $this> */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
