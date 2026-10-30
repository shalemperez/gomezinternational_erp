<?php

namespace App\Models;

use App\Enums\TaxIdType;
use App\Enums\TaxpayerType;
use App\Models\Concerns\LogsModelActivity;
use App\Support\FiscalId;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property TaxIdType $tax_id_type
 * @property TaxpayerType $taxpayer_type
 */
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory, LogsModelActivity;

    protected $fillable = [
        'code', 'business_name', 'trade_name', 'tax_id_type', 'tax_id', 'taxpayer_type', 'address', 'city', 'phone', 'email', 'salesperson_id',
        'credit_days', 'credit_limit', 'price_list_id', 'currency_id', 'notes', 'tax_id_validation_overridden', 'tax_id_override_reason',
        'tax_id_override_by', 'tax_id_override_at', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'tax_id_type' => TaxIdType::class, 'taxpayer_type' => TaxpayerType::class, 'credit_days' => 'integer', 'credit_limit' => 'decimal:2',
            'tax_id_validation_overridden' => 'boolean', 'tax_id_override_at' => 'datetime', 'is_active' => 'boolean',
        ];
    }

    /** @return Attribute<string|null, never> */
    protected function formattedTaxId(): Attribute
    {
        return Attribute::get(fn () => $this->tax_id ? FiscalId::format($this->tax_id) : null);
    }

    /**
     * @param  Builder<Client>  $query
     * @return Builder<Client>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('catalog.clients.view-all')) {
            return $query;
        }

        return $query->where('salesperson_id', $user->salesperson instanceof Salesperson ? $user->salesperson->id : -1);
    }

    /** @return HasMany<ClientContact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(ClientContact::class);
    }

    /** @return BelongsTo<Salesperson, $this> */
    public function salesperson(): BelongsTo
    {
        return $this->belongsTo(Salesperson::class);
    }

    /** @return BelongsTo<PriceList, $this> */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
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
