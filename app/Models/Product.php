<?php

namespace App\Models;

use App\Models\Concerns\LogsModelActivity;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, LogsModelActivity;

    protected $fillable = [
        'sku', 'barcode', 'name', 'brand_id', 'category_id', 'unit_of_measure_id', 'units_per_box', 'weight_kg', 'volume_liters',
        'alcohol_degree', 'itbis_rate', 'tracks_lots', 'tariff_code', 'minimum_stock', 'description', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'units_per_box' => 'integer', 'weight_kg' => 'decimal:4', 'volume_liters' => 'decimal:4', 'alcohol_degree' => 'decimal:2',
            'itbis_rate' => 'decimal:2', 'tracks_lots' => 'boolean', 'minimum_stock' => 'decimal:4', 'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<ProductCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    /** @return BelongsTo<UnitOfMeasure, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'unit_of_measure_id');
    }

    /** @return BelongsToMany<AdditionalTax, $this> */
    public function additionalTaxes(): BelongsToMany
    {
        return $this->belongsToMany(AdditionalTax::class, 'additional_tax_product');
    }
}
