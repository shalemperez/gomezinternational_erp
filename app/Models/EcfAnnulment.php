<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $status
 * @property int $ranges_count
 * @property int $quantity
 * @property array<string, mixed>|null $response
 * @property CarbonInterface|null $sent_at
 */
class EcfAnnulment extends Model
{
    protected $fillable = ['environment', 'status', 'ranges_count', 'quantity', 'xml_path', 'response', 'sent_at', 'created_by'];

    protected function casts(): array
    {
        return ['ranges_count' => 'integer', 'quantity' => 'integer', 'response' => 'array', 'sent_at' => 'datetime'];
    }

    /** @return HasMany<EcfAnnulmentRange, $this> */
    public function ranges(): HasMany
    {
        return $this->hasMany(EcfAnnulmentRange::class, 'ecf_annulment_id');
    }
}
