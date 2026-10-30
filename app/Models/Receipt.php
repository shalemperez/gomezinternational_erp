<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\ReceiptStatus;
use App\Models\Concerns\ImmutableWhenIssued;
use App\Models\Concerns\LogsModelActivity;
use App\Models\Concerns\VisibleToSalesperson;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $number
 * @property ReceiptStatus $status
 * @property int $client_id
 * @property int|null $salesperson_id
 * @property int $currency_id
 * @property string $exchange_rate
 * @property Carbon $receipt_date
 * @property PaymentMethod $payment_method
 * @property int|null $financial_account_id
 * @property string|null $reference
 * @property string $amount_received
 * @property string $retention_itbis
 * @property string $retention_isr
 * @property string $applied_total
 * @property string $unapplied_amount
 * @property string|null $notes
 * @property Carbon|null $posted_at
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by
 * @property string|null $cancel_reason
 * @property int $created_by
 */
class Receipt extends Model
{
    use ImmutableWhenIssued, LogsModelActivity, VisibleToSalesperson;

    public const VIEW_ALL_PERMISSION = 'sales.invoices.view-all';

    protected $fillable = [
        'number', 'status', 'client_id', 'salesperson_id', 'currency_id', 'exchange_rate', 'receipt_date', 'payment_method', 'financial_account_id',
        'reference', 'amount_received', 'retention_itbis', 'retention_isr', 'applied_total', 'unapplied_amount', 'notes', 'posted_at',
        'cancelled_at', 'cancelled_by', 'cancel_reason', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReceiptStatus::class, 'payment_method' => PaymentMethod::class, 'receipt_date' => 'date:Y-m-d',
            'exchange_rate' => 'decimal:6', 'amount_received' => 'decimal:2', 'retention_itbis' => 'decimal:2', 'retention_isr' => 'decimal:2',
            'applied_total' => 'decimal:2', 'unapplied_amount' => 'decimal:2', 'posted_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    protected function issuedAtColumn(): string
    {
        return 'posted_at';
    }

    /** @return list<string> */
    protected function mutableWhenIssued(): array
    {
        return ['status', 'applied_total', 'unapplied_amount', 'cancelled_at', 'cancelled_by', 'cancel_reason'];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Salesperson, $this> */
    public function salesperson(): BelongsTo
    {
        return $this->belongsTo(Salesperson::class);
    }

    /** @return BelongsTo<Currency, $this> */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /** @return BelongsTo<FinancialAccount, $this> */
    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    /** @return BelongsTo<User, $this> */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<ReceiptApplication, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(ReceiptApplication::class);
    }
}
