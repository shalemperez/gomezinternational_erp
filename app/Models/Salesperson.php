<?php

namespace App\Models;

use App\Models\Concerns\LogsModelActivity;
use Database\Factories\SalespersonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Salesperson extends Model
{
    /** @use HasFactory<SalespersonFactory> */
    use HasFactory, LogsModelActivity;

    protected $table = 'salespeople';

    protected $fillable = ['code', 'name', 'email', 'phone', 'user_id', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
