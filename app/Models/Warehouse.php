<?php

namespace App\Models;

use App\Models\Concerns\LogsModelActivity;
use Database\Factories\WarehouseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    /** @use HasFactory<WarehouseFactory> */
    use HasFactory, LogsModelActivity;

    protected $fillable = ['code', 'name', 'address', 'is_default', 'is_active'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->first();
    }
}
