<?php

namespace App\Models;

use App\Models\Concerns\LogsModelActivity;
use Database\Factories\ProductCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductCategory extends Model
{
    /** @use HasFactory<ProductCategoryFactory> */
    use HasFactory, LogsModelActivity;

    protected $fillable = ['name', 'parent_id', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<ProductCategory, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'parent_id');
    }

    /** @return HasMany<ProductCategory, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(ProductCategory::class, 'parent_id');
    }
}
