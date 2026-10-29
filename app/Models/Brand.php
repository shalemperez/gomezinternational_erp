<?php

namespace App\Models;

use App\Models\Concerns\LogsModelActivity;
use Database\Factories\BrandFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{
    /** @use HasFactory<BrandFactory> */
    use HasFactory, LogsModelActivity;

    protected $fillable = ['name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
