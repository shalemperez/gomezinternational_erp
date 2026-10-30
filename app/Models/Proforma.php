<?php

namespace App\Models;

use App\Enums\ProformaStatus;
use App\Models\Concerns\LogsModelActivity;
use App\Models\Concerns\VisibleToSalesperson;
use App\Models\Contracts\HasDocumentLines;
use Database\Factories\ProformaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $number
 * @property ProformaStatus $status
 * @property int $client_id
 * @property int $salesperson_id
 * @property int $currency_id
 * @property string $exchange_rate
 * @property Carbon $issue_date
 * @property Carbon $valid_until
 * @property int|null $price_list_id
 * @property string $subtotal
 * @property string $discount_total
 * @property string $taxable_total
 * @property string $itbis_total
 * @property string $additional_tax_total
 * @property string $total
 * @property string $total_base
 * @property string|null $notes
 * @property Carbon|null $sent_at
 * @property Carbon|null $approved_at
 * @property int|null $approved_by
 * @property string|null $approval_note
 * @property Carbon|null $rejected_at
 * @property string|null $rejection_note
 * @property Carbon|null $invoiced_at
 * @property int $created_by
 */
class Proforma extends Model implements HasDocumentLines
{
    /** @use HasFactory<ProformaFactory> */
    use HasFactory, LogsModelActivity, VisibleToSalesperson;

    public const VIEW_ALL_PERMISSION = 'sales.proformas.view-all';

    protected $fillable = [
        'number', 'status', 'client_id', 'salesperson_id', 'currency_id', 'exchange_rate', 'issue_date', 'valid_until', 'price_list_id',
        'subtotal', 'discount_total', 'taxable_total', 'itbis_total', 'additional_tax_total', 'total', 'total_base', 'notes',
        'sent_at', 'approved_at', 'approved_by', 'approval_note', 'rejected_at', 'rejection_note', 'invoiced_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProformaStatus::class,
            'exchange_rate' => 'decimal:6',
            'issue_date' => 'date:Y-m-d',
            'valid_until' => 'date:Y-m-d',
            'subtotal' => 'decimal:2', 'discount_total' => 'decimal:2', 'taxable_total' => 'decimal:2', 'itbis_total' => 'decimal:2',
            'additional_tax_total' => 'decimal:2', 'total' => 'decimal:2', 'total_base' => 'decimal:2',
            'sent_at' => 'datetime', 'approved_at' => 'datetime', 'rejected_at' => 'datetime', 'invoiced_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === ProformaStatus::Draft;
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

    /** @return BelongsTo<PriceList, $this> */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<ProformaLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(ProformaLine::class)->orderBy('line_number');
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
