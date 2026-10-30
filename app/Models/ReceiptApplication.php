<?php

namespace App\Models;

use App\Models\Concerns\LogsModelActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $receipt_id
 * @property int $invoice_id
 * @property string $amount
 * @property string $source
 * @property int $created_by
 * @property Carbon $created_at
 */
class ReceiptApplication extends Model
{
    use LogsModelActivity;

    protected $fillable = ['receipt_id', 'invoice_id', 'amount', 'source', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    /** @return BelongsTo<Receipt, $this> */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
