<?php

namespace App\Models;

use App\Models\Concerns\LogsModelActivity;
use Database\Factories\CurrencyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Currency extends Model
{
    /** @use HasFactory<CurrencyFactory> */
    use HasFactory, LogsModelActivity;

    protected $fillable = ['code', 'name', 'symbol', 'decimal_places', 'is_base', 'is_active'];

    protected function casts(): array
    {
        return ['is_base' => 'boolean', 'is_active' => 'boolean', 'decimal_places' => 'integer'];
    }

    /**
     * @param  Builder<Currency>  $query
     * @return Builder<Currency>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public static function base(): self
    {
        return static::query()->where('is_base', true)->firstOrFail();
    }

    /** @return HasMany<ExchangeRate, $this> */
    public function rates(): HasMany
    {
        return $this->hasMany(ExchangeRate::class);
    }
}
