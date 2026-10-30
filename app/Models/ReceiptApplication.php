<?php

namespace App\Models;

use App\Models\Concerns\LogsModelActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $receipt_id
 * @property int|null $credit_note_id
 * @property int $invoice_id
 * @property string $amount
 * @property string $source
 * @property Carbon $applied_on
 * @property int $created_by
 * @property Carbon $created_at
 */
class ReceiptApplication extends Model
{
    use LogsModelActivity;

    protected $fillable = ['receipt_id', 'credit_note_id', 'invoice_id', 'amount', 'source', 'applied_on', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'applied_on' => 'date:Y-m-d'];
    }

    /** @return BelongsTo<CreditNote, $this> */
    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
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
