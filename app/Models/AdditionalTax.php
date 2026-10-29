<?php

namespace App\Models;

use App\Enums\AdditionalTaxCalculationType;
use App\Models\Concerns\LogsModelActivity;
use Database\Factories\AdditionalTaxFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property AdditionalTaxCalculationType $calculation_type
 */
class AdditionalTax extends Model
{
    /** @use HasFactory<AdditionalTaxFactory> */
    use HasFactory, LogsModelActivity;

    protected $fillable = ['code', 'name', 'calculation_type', 'rate', 'amount', 'included_in_itbis_base', 'dgii_code', 'is_active'];

    protected function casts(): array
    {
        return [
            'calculation_type' => AdditionalTaxCalculationType::class,
            'rate' => 'decimal:4',
            'amount' => 'decimal:4',
            'included_in_itbis_base' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'additional_tax_product');
    }
}
