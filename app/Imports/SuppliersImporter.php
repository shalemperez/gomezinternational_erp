<?php

namespace App\Imports;

use App\Models\Currency;
use App\Models\Supplier;
use App\Rules\DominicanTaxId;
use App\Support\Countries;
use App\Support\FiscalId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SuppliersImporter extends BaseImporter
{
    /** @var array<string, true> codes already seen in this file */
    private array $seenCodes = [];

    public static function type(): string
    {
        return 'suppliers';
    }

    public function label(): string
    {
        return 'Proveedores';
    }

    public function permission(): string
    {
        return 'catalog.suppliers.import';
    }

    public function columns(): array
    {
        return [
            ['key' => 'code', 'label' => 'Código', 'required' => false, 'example' => 'PRV-00001'],
            ['key' => 'name', 'label' => 'Nombre', 'required' => true, 'example' => 'Importadora Global LLC'],
            ['key' => 'country_code', 'label' => 'País', 'required' => true, 'example' => 'US'],
            ['key' => 'tax_id', 'label' => 'RNC', 'required' => false, 'example' => '1-01-85004-3'],
            ['key' => 'foreign_tax_id', 'label' => 'ID fiscal extranjera', 'required' => false, 'example' => 'US-12-3456789'],
            ['key' => 'currency_code', 'label' => 'Moneda', 'required' => true, 'example' => 'USD'],
            ['key' => 'credit_days', 'label' => 'Días de crédito', 'required' => false, 'example' => '60'],
            ['key' => 'address', 'label' => 'Dirección', 'required' => false, 'example' => '1 Main St'],
            ['key' => 'phone', 'label' => 'Teléfono', 'required' => false, 'example' => '+1 305 555 0100'],
            ['key' => 'email', 'label' => 'Correo', 'required' => false, 'example' => 'sales@global.test'],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function normalize(array $row, array $meta): array
    {
        $row['country_code'] = isset($row['country_code']) ? strtoupper($row['country_code']) : null;
        $rawTaxId = $row['tax_id'] ?? null;
        $digits = $rawTaxId === null ? '' : FiscalId::normalize((string) $rawTaxId);
        if ($digits !== '' && is_numeric($rawTaxId) && strlen($digits) < 11 && strlen($digits) !== 9) {
            $digits = str_pad($digits, strlen($digits) < 9 ? 9 : 11, '0', STR_PAD_LEFT);
        }
        $row['tax_id'] = $digits === '' ? null : $digits;
        $row['currency_code'] = isset($row['currency_code']) ? strtoupper($row['currency_code']) : null;
        $row['email'] = isset($row['email']) ? strtolower($row['email']) : null;

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function rules(array $row, array $meta): array
    {
        $taxIdRules = ['nullable', 'string'];
        if (filled($row['tax_id'] ?? null)) {
            if (($row['country_code'] ?? null) === 'DO') {
                $taxIdRules[] = new DominicanTaxId(strlen($row['tax_id']) === 11 ? 'cedula' : 'rnc');
            } else {
                $taxIdRules = ['prohibited'];
            }
        }
        $existing = $this->findExisting($row, $meta);
        $code = $row['code'] ?? null;
        $codeRules = ['nullable', 'string', 'max:20', 'alpha_dash', Rule::unique('suppliers', 'code')->ignore($existing?->getKey())];
        if (is_string($code) && $code !== '') {
            $codeRules[] = function (string $attribute, mixed $value, \Closure $fail) use ($code): void {
                if (isset($this->seenCodes[$code])) {
                    $fail('Código duplicado en el archivo.');
                }
                $this->seenCodes[$code] = true;
            };
        }

        return [
            'code' => $codeRules,
            'name' => ['required', 'string', 'max:200'],
            'country_code' => ['required', Rule::in(array_keys(Countries::OPTIONS))],
            'tax_id' => $taxIdRules,
            'foreign_tax_id' => ['nullable', 'string', 'max:50'],
            'currency_code' => ['required', 'string', 'exists:currencies,code'],
            'credit_days' => ['nullable', 'integer', 'between:0,365'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.unique' => 'El código ya pertenece a otro proveedor.',
            'tax_id.prohibited' => 'El RNC/cédula solo aplica a proveedores locales.',
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $meta
     */
    public function naturalKey(array $row, array $meta): ?string
    {
        return $row['tax_id'] ?: $row['foreign_tax_id'] ?: $row['code'] ?: null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $meta
     */
    public function findExisting(array $row, array $meta): ?Model
    {
        if (! empty($row['tax_id'])) {
            return Supplier::query()->where('tax_id', $row['tax_id'])->first();
        }
        if (! empty($row['code'])) {
            return Supplier::query()->where('code', $row['code'])->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $meta
     */
    public function persist(array $row, ?Model $existing, array $meta): Model
    {
        /** @var Supplier $supplier */
        $supplier = $existing ?? new Supplier;
        $attributes = [
            'name' => $row['name'] ?? null,
            'currency_id' => Currency::query()->where('code', $row['currency_code'])->value('id'),
            'credit_days' => filled($row['credit_days'] ?? null) ? (int) $row['credit_days'] : null,
            'address' => $row['address'] ?? null,
            'phone' => $row['phone'] ?? null,
            'email' => $row['email'] ?? null,
        ];
        if ($existing === null) {
            $attributes['country_code'] = $row['country_code'] ?? null;
        }
        // An existing record's fiscal identity (country, RNC, foreign tax id) is never changed by an import.
        if ($existing === null || blank($supplier->tax_id)) {
            $attributes['tax_id'] = $row['tax_id'] ?? null;
        }
        if ($existing === null || blank($supplier->foreign_tax_id)) {
            $attributes['foreign_tax_id'] = $row['foreign_tax_id'] ?? null;
        }
        // On update, blank cells and absent columns never overwrite existing data.
        $supplier->fill($existing === null ? $attributes : array_filter($attributes, fn (mixed $v): bool => $v !== null));
        if ($existing === null) {
            $supplier->credit_days ??= 0;
        }
        if (! $supplier->exists && filled($row['code'] ?? null)) {
            $supplier->code = (string) $row['code'];
        } elseif (! $supplier->exists && blank($supplier->code)) {
            $supplier->setAttribute('id', (int) DB::scalar("select nextval(pg_get_serial_sequence('suppliers', 'id'))"));
            $supplier->code = 'PRV-'.str_pad((string) $supplier->getKey(), 5, '0', STR_PAD_LEFT);
        }
        $supplier->save();

        return $supplier;
    }
}
