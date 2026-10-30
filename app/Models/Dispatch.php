<?php

namespace App\Models;

use App\Enums\DispatchStatus;
use App\Models\Concerns\ImmutableWhenIssued;
use App\Models\Concerns\LogsModelActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $number
 * @property DispatchStatus $status
 * @property int $invoice_id
 * @property int $client_id
 * @property int $warehouse_id
 * @property Carbon $dispatch_date
 * @property string|null $driver_name
 * @property string|null $vehicle
 * @property string|null $notes
 * @property string $total_cost
 * @property Carbon|null $confirmed_at
 * @property int|null $confirmed_by
 * @property int $created_by
 */
class Dispatch extends Model
{
    use ImmutableWhenIssued, LogsModelActivity;

    protected $fillable = [
        'number', 'status', 'invoice_id', 'client_id', 'warehouse_id', 'dispatch_date', 'driver_name', 'vehicle', 'notes',
        'total_cost', 'confirmed_at', 'confirmed_by', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => DispatchStatus::class, 'dispatch_date' => 'date:Y-m-d', 'total_cost' => 'decimal:2', 'confirmed_at' => 'datetime',
        ];
    }

    /** @return list<string> */
    protected function mutableWhenIssued(): array
    {
        return [];
    }

    protected function issuedAtColumn(): string
    {
        return 'confirmed_at';
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

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<User, $this> */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<DispatchLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(DispatchLine::class)->orderBy('id');
    }
}
