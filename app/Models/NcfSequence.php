<?php

namespace App\Models;

use App\Enums\NcfType;
use App\Models\Concerns\LogsModelActivity;
use Database\Factories\NcfSequenceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property NcfType $ncf_type
 * @property Carbon|null $expires_on
 */
class NcfSequence extends Model
{
    /** @use HasFactory<NcfSequenceFactory> */
    use HasFactory, LogsModelActivity;

    protected $fillable = ['ncf_type', 'range_from', 'range_to', 'next_number', 'expires_on', 'authorization_number', 'is_active', 'exhausted_at', 'notes'];

    protected function casts(): array
    {
        return [
            'ncf_type' => NcfType::class, 'range_from' => 'integer', 'range_to' => 'integer', 'next_number' => 'integer',
            'expires_on' => 'date:Y-m-d', 'is_active' => 'boolean', 'exhausted_at' => 'datetime',
        ];
    }

    /** @return Attribute<int<0, max>, never> */
    protected function remaining(): Attribute
    {
        return Attribute::get(fn () => max(0, $this->range_to - $this->next_number + 1));
    }

    /** @return Attribute<int, never> */
    protected function used(): Attribute
    {
        return Attribute::get(fn () => $this->next_number - $this->range_from);
    }

    /** @param  Builder<NcfSequence>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
