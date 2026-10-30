<?php

namespace App\Models;

use App\Models\Concerns\LogsModelActivity;
use Database\Factories\SupplierContactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierContact extends Model
{
    /** @use HasFactory<SupplierContactFactory> */
    use HasFactory, LogsModelActivity;

    protected $fillable = ['supplier_id', 'name', 'position', 'email', 'phone', 'is_primary'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
