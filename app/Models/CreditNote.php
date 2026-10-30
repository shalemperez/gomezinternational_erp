<?php

namespace App\Models;

use App\Enums\CreditNoteReason;
use App\Enums\NcfType;
use App\Models\Concerns\ImmutableWhenIssued;
use App\Models\Concerns\LogsModelActivity;
use App\Models\Concerns\VisibleToSalesperson;
use App\Models\Contracts\HasDocumentLines;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $number
 * @property string $status
 * @property string $fiscal_mode
 * @property string $ncf
 * @property NcfType $ncf_type
 * @property Carbon|null $ncf_expires_on
 * @property int|null $ncf_sequence_id
 * @property int $invoice_id
 * @property string|null $modified_ncf
 * @property int $client_id
 * @property int $salesperson_id
 * @property int|null $warehouse_id
 * @property int $currency_id
 * @property string $exchange_rate
 * @property Carbon $issue_date
 * @property CreditNoteReason $reason
 * @property string $subtotal
 * @property string $discount_total
 * @property string $taxable_total
 * @property string $itbis_total
 * @property string $additional_tax_total
 * @property string $total
 * @property string $total_base
 * @property string $returned_cost
 * @property string|null $notes
 * @property Carbon|null $issued_at
 * @property int $created_by
 */
class CreditNote extends Model implements HasDocumentLines
{
    use ImmutableWhenIssued, LogsModelActivity, VisibleToSalesperson;

    public const VIEW_ALL_PERMISSION = 'sales.invoices.view-all';

    protected $fillable = [
        'number', 'status', 'fiscal_mode', 'ncf', 'ncf_type', 'ncf_expires_on', 'ncf_sequence_id', 'invoice_id', 'modified_ncf', 'client_id',
        'salesperson_id', 'warehouse_id', 'currency_id', 'exchange_rate', 'issue_date', 'reason', 'subtotal', 'discount_total', 'taxable_total',
        'itbis_total', 'additional_tax_total', 'total', 'total_base', 'returned_cost', 'notes', 'issued_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'ncf_type' => NcfType::class, 'reason' => CreditNoteReason::class,
            'ncf_expires_on' => 'date:Y-m-d', 'issue_date' => 'date:Y-m-d', 'exchange_rate' => 'decimal:6',
            'subtotal' => 'decimal:2', 'discount_total' => 'decimal:2', 'taxable_total' => 'decimal:2', 'itbis_total' => 'decimal:2',
            'additional_tax_total' => 'decimal:2', 'total' => 'decimal:2', 'total_base' => 'decimal:2', 'returned_cost' => 'decimal:2',
            'issued_at' => 'datetime',
        ];
    }

    /** @return list<string> */
    protected function mutableWhenIssued(): array
    {
        return ['status'];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
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

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<CreditNoteLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(CreditNoteLine::class)->orderBy('line_number');
    }
}
