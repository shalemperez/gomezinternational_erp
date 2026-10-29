<?php

namespace App\Models;

use App\Models\Concerns\LogsModelActivity;
use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
    use HasFactory, LogsModelActivity;

    protected $fillable = [
        'code', 'name', 'country_code', 'tax_id', 'foreign_tax_id', 'currency_id', 'credit_days', 'address', 'phone', 'email', 'notes',
        'tax_id_validation_overridden', 'tax_id_override_reason', 'tax_id_override_by', 'tax_id_override_at', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'credit_days' => 'integer', 'tax_id_validation_overridden' => 'boolean', 'tax_id_override_at' => 'datetime', 'is_active' => 'boolean',
        ];
    }

    /** @return Attribute<bool, never> */
    protected function isLocal(): Attribute
    {
        return Attribute::get(fn () => $this->country_code === 'DO');
    }

    /** @return HasMany<SupplierContact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(SupplierContact::class);
    }

    /** @return BelongsTo<Currency, $this> */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /** @return BelongsTo<User, $this> */
    public function taxIdOverrideBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tax_id_override_by');
    }
}
