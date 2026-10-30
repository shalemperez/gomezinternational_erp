<?php

namespace App\Models;

use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Enums\NcfType;
use App\Enums\PaymentTerms;
use App\Models\Concerns\ImmutableWhenIssued;
use App\Models\Concerns\LogsModelActivity;
use App\Models\Concerns\VisibleToSalesperson;
use App\Models\Contracts\HasDocumentLines;
use App\Support\Money\Decimal;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $number
 * @property InvoiceKind $kind
 * @property InvoiceStatus $status
 * @property string|null $fiscal_mode
 * @property string|null $ncf
 * @property NcfType|null $ncf_type
 * @property Carbon|null $ncf_expires_on
 * @property int $client_id
 * @property string|null $client_name
 * @property string|null $client_tax_id
 * @property string|null $client_tax_id_type
 * @property string|null $client_address
 * @property int $salesperson_id
 * @property int|null $proforma_id
 * @property int|null $warehouse_id
 * @property int $currency_id
 * @property string $exchange_rate
 * @property Carbon $issue_date
 * @property Carbon $due_date
 * @property PaymentTerms $payment_terms
 * @property int $credit_days
 * @property string $subtotal
 * @property string $discount_total
 * @property string $taxable_total
 * @property string $itbis_total
 * @property string $additional_tax_total
 * @property string $total
 * @property string $total_base
 * @property string $paid_amount
 * @property string $credited_amount
 * @property string $debited_amount
 * @property string $balance
 * @property Carbon|null $issued_at
 * @property Carbon|null $cancelled_at
 * @property int $created_by
 */
class Invoice extends Model implements HasDocumentLines
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory, ImmutableWhenIssued, LogsModelActivity, VisibleToSalesperson;

    public const VIEW_ALL_PERMISSION = 'sales.invoices.view-all';

    protected $fillable = [
        'number', 'kind', 'status', 'fiscal_mode', 'ncf', 'ncf_type', 'ncf_expires_on', 'ncf_sequence_id', 'client_id', 'client_name', 'client_tax_id',
        'client_tax_id_type', 'client_address', 'salesperson_id', 'proforma_id', 'warehouse_id', 'currency_id', 'exchange_rate', 'issue_date', 'due_date',
        'payment_terms', 'credit_days', 'subtotal', 'discount_total', 'taxable_total', 'itbis_total', 'additional_tax_total', 'total', 'total_base',
        'paid_amount', 'credited_amount', 'debited_amount', 'balance', 'dispatch_status', 'notes', 'issued_at', 'sent_at', 'cancelled_at', 'cancelled_by',
        'cancel_reason', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'kind' => InvoiceKind::class, 'status' => InvoiceStatus::class, 'ncf_type' => NcfType::class, 'payment_terms' => PaymentTerms::class,
            'ncf_expires_on' => 'date:Y-m-d', 'issue_date' => 'date:Y-m-d', 'due_date' => 'date:Y-m-d', 'credit_days' => 'integer',
            'exchange_rate' => 'decimal:6',
            'subtotal' => 'decimal:2', 'discount_total' => 'decimal:2', 'taxable_total' => 'decimal:2', 'itbis_total' => 'decimal:2',
            'additional_tax_total' => 'decimal:2', 'total' => 'decimal:2', 'total_base' => 'decimal:2',
            'paid_amount' => 'decimal:2', 'credited_amount' => 'decimal:2', 'debited_amount' => 'decimal:2', 'balance' => 'decimal:2',
            'issued_at' => 'datetime', 'sent_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === InvoiceStatus::Draft;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid], true);
    }

    /** Recomputes the balance and the payment status from the stored amounts; a cancelled invoice keeps its status. */
    public function refreshFinancials(): void
    {
        $balance = Decimal::money(Decimal::of($this->total)->plus(Decimal::of($this->debited_amount))->minus(Decimal::of($this->credited_amount))->minus(Decimal::of($this->paid_amount)));
        $status = match (true) {
            $this->status === InvoiceStatus::Cancelled => InvoiceStatus::Cancelled,
            Decimal::cmp($balance, '0') <= 0 => InvoiceStatus::Paid,
            Decimal::cmp($this->paid_amount, '0') > 0 => InvoiceStatus::PartiallyPaid,
            default => InvoiceStatus::Issued,
        };

        $this->forceFill(['balance' => $balance, 'status' => $status])->save();
    }

    /** @return list<string> */
    protected function mutableWhenIssued(): array
    {
        return ['status', 'paid_amount', 'credited_amount', 'debited_amount', 'balance', 'cancelled_at', 'cancelled_by', 'cancel_reason', 'sent_at', 'dispatch_status'];
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

    /** @return BelongsTo<Proforma, $this> */
    public function proforma(): BelongsTo
    {
        return $this->belongsTo(Proforma::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<Currency, $this> */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /** @return BelongsTo<NcfSequence, $this> */
    public function ncfSequence(): BelongsTo
    {
        return $this->belongsTo(NcfSequence::class);
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

    /** @return HasMany<InvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('line_number');
    }

    /** @return HasMany<CreditNote, $this> */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    /** @return HasMany<DebitNote, $this> */
    public function debitNotes(): HasMany
    {
        return $this->hasMany(DebitNote::class);
    }
}
