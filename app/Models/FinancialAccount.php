<?php

namespace App\Models;

use App\Enums\FinancialAccountType;
use App\Models\Concerns\LogsModelActivity;
use Database\Factories\FinancialAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property FinancialAccountType $type
 */
class FinancialAccount extends Model
{
    /** @use HasFactory<FinancialAccountFactory> */
    use HasFactory, LogsModelActivity;

    protected $fillable = ['type', 'name', 'bank_name', 'account_number', 'currency_id', 'is_active'];

    protected function casts(): array
    {
        return ['type' => FinancialAccountType::class, 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<Currency, $this> */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
