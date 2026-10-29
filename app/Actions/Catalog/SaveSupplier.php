<?php

namespace App\Actions\Catalog;

use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class SaveSupplier
{
    public function __construct(private readonly ApplyTaxIdOverride $applyOverride, private readonly ReplaceContacts $replaceContacts) {}

    /** @param array<string, mixed> $data validated StoreSupplierRequest/UpdateSupplierRequest data */
    public function handle(array $data, User $actor, ?Supplier $supplier = null): Supplier
    {
        return DB::transaction(function () use ($data, $actor, $supplier): Supplier {
            $supplier ??= new Supplier;
            $attributes = Arr::except($data, ['contacts', 'tax_id_override', 'tax_id_override_reason']);
            if (blank($attributes['code'] ?? null)) {
                unset($attributes['code']); // blank code on update keeps the existing one
            }
            $supplier->fill($attributes);
            if (! $supplier->exists && blank($supplier->code)) {
                // Reserve the id from the table's own sequence so the code is known before the single INSERT (race-safe, one audit row).
                /** @var int<1, max> $reservedId */
                $reservedId = (int) DB::scalar("select nextval(pg_get_serial_sequence('suppliers', 'id'))");
                $supplier->id = $reservedId;
                $supplier->code = 'PRV-'.str_pad((string) $supplier->id, 5, '0', STR_PAD_LEFT);
            }
            $supplier->save();
            $this->applyOverride->handle($supplier, $data, $actor, 'suppliers');
            $this->replaceContacts->handle($supplier->contacts(), $data['contacts'] ?? []);

            return $supplier;
        });
    }
}
