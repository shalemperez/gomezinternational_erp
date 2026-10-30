<?php

namespace App\Imports;

use App\Enums\TaxIdType;
use App\Enums\TaxpayerType;
use App\Models\Client;
use App\Models\Currency;
use App\Models\PriceList;
use App\Models\Salesperson;
use App\Rules\DominicanTaxId;
use App\Support\FiscalId;
use App\Support\Money\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ClientsImporter extends BaseImporter
{
    /** @var array<string, true> codes already seen in this file */
    private array $seenCodes = [];

    public static function type(): string
    {
        return 'clients';
    }

    public function label(): string
    {
        return 'Clientes';
    }

    public function permission(): string
    {
        return 'catalog.clients.import';
    }

    public function columns(): array
    {
        return [
            ['key' => 'code', 'label' => 'Código', 'required' => false, 'example' => 'CLI-00001'],
            ['key' => 'business_name', 'label' => 'Razón social', 'required' => true, 'example' => 'Distribuidora Ejemplo SRL'],
            ['key' => 'trade_name', 'label' => 'Nombre comercial', 'required' => false, 'example' => 'Ejemplo'],
            ['key' => 'tax_id_type', 'label' => 'Tipo ID', 'required' => true, 'example' => 'RNC'],
            ['key' => 'tax_id', 'label' => 'RNC/Cédula', 'required' => false, 'example' => '1-01-85004-3'],
            ['key' => 'taxpayer_type', 'label' => 'Tipo contribuyente', 'required' => true, 'example' => 'Persona jurídica'],
            ['key' => 'address', 'label' => 'Dirección', 'required' => false, 'example' => 'Av. Principal 1'],
            ['key' => 'city', 'label' => 'Ciudad', 'required' => false, 'example' => 'Santo Domingo'],
            ['key' => 'phone', 'label' => 'Teléfono', 'required' => false, 'example' => '809-555-0000'],
            ['key' => 'email', 'label' => 'Correo', 'required' => false, 'example' => 'ventas@ejemplo.test'],
            ['key' => 'salesperson_code', 'label' => 'Vendedor (código)', 'required' => false, 'example' => 'VEN-01'],
            ['key' => 'credit_days', 'label' => 'Días de crédito', 'required' => false, 'example' => '30'],
            ['key' => 'credit_limit', 'label' => 'Límite de crédito', 'required' => false, 'example' => '100000'],
            ['key' => 'price_list_name', 'label' => 'Lista de precios', 'required' => false, 'example' => 'Distribuidores'],
            ['key' => 'currency_code', 'label' => 'Moneda', 'required' => true, 'example' => 'DOP'],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function normalize(array $row, array $meta): array
    {
        $typeMap = ['rnc' => 'rnc', 'cedula' => 'cedula', 'ninguno' => 'none', 'none' => 'none'];
        $row['tax_id_type'] = $typeMap[Str::slug((string) ($row['tax_id_type'] ?? ''))] ?? $row['tax_id_type'];
        $taxpayerMap = [];
        foreach (TaxpayerType::cases() as $case) {
            $taxpayerMap[Str::slug($case->label())] = $case->value;
            $taxpayerMap[Str::slug($case->value)] = $case->value;
        }
        $row['taxpayer_type'] = $taxpayerMap[Str::slug((string) ($row['taxpayer_type'] ?? ''))] ?? $row['taxpayer_type'];
        $rawTaxId = $row['tax_id'] ?? null;
        $digits = $rawTaxId === null ? '' : FiscalId::normalize((string) $rawTaxId);
        if ($digits !== '' && is_numeric($rawTaxId)) { // a numeric cell lost its leading zeros
            $digits = str_pad($digits, $row['tax_id_type'] === 'cedula' ? 11 : 9, '0', STR_PAD_LEFT);
        }
        $row['tax_id'] = $row['tax_id_type'] === 'none' || $digits === '' ? null : $digits;
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
        if (in_array($row['tax_id_type'] ?? null, ['rnc', 'cedula'], true)) {
            $taxIdRules = ['required', 'string', new DominicanTaxId($row['tax_id_type'])];
        }
        $existing = $this->findExisting($row, $meta);
        $code = $row['code'] ?? null;
        $codeRules = ['nullable', 'string', 'max:20', 'alpha_dash', Rule::unique('clients', 'code')->ignore($existing?->getKey())];
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
            'business_name' => ['required', 'string', 'max:200'],
            'trade_name' => ['nullable', 'string', 'max:200'],
            'tax_id_type' => ['required', Rule::enum(TaxIdType::class), function (string $attribute, mixed $value, \Closure $fail) use ($row): void {
                if (($row['taxpayer_type'] ?? null) === 'juridica' && $value !== 'rnc') {
                    $fail('Una persona jurídica debe identificarse con RNC.');
                }
            }],
            'tax_id' => $taxIdRules,
            'taxpayer_type' => ['required', Rule::enum(TaxpayerType::class)],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'salesperson_code' => ['nullable', 'string', Rule::exists('salespeople', 'code')->where('is_active', true)],
            'credit_days' => ['nullable', 'integer', 'between:0,365'],
            'credit_limit' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'price_list_name' => ['nullable', 'string', Rule::exists('price_lists', 'name')->where('is_active', true)],
            'currency_code' => ['required', 'string', 'exists:currencies,code'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.unique' => 'El código ya pertenece a otro cliente.',
            'salesperson_code.exists' => 'El vendedor no existe o está inactivo.',
            'price_list_name.exists' => 'La lista de precios no existe o está inactiva.',
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $meta
     */
    public function naturalKey(array $row, array $meta): ?string
    {
        return $row['tax_id'] ?: ($row['code'] ?: null);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $meta
     */
    public function findExisting(array $row, array $meta): ?Model
    {
        if (! empty($row['tax_id'])) {
            return Client::query()->where('tax_id', $row['tax_id'])->first();
        }
        if (! empty($row['code'])) {
            return Client::query()->where('code', $row['code'])->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $meta
     */
    public function persist(array $row, ?Model $existing, array $meta): Model
    {
        /** @var Client $client */
        $client = $existing ?? new Client;
        $attributes = [
            'business_name' => $row['business_name'] ?? null,
            'trade_name' => $row['trade_name'] ?? null,
            'taxpayer_type' => $row['taxpayer_type'] ?? null,
            'address' => $row['address'] ?? null,
            'city' => $row['city'] ?? null,
            'phone' => $row['phone'] ?? null,
            'email' => $row['email'] ?? null,
            'salesperson_id' => filled($row['salesperson_code'] ?? null) ? Salesperson::query()->where('code', $row['salesperson_code'])->value('id') : null,
            'credit_days' => filled($row['credit_days'] ?? null) ? (int) $row['credit_days'] : null,
            'credit_limit' => filled($row['credit_limit'] ?? null) ? Decimal::money((string) $row['credit_limit']) : null,
            'price_list_id' => filled($row['price_list_name'] ?? null) ? PriceList::query()->where('name', $row['price_list_name'])->value('id') : null,
            'currency_id' => Currency::query()->where('code', $row['currency_code'])->value('id'),
        ];
        if ($existing === null || blank($client->tax_id)) {
            $attributes['tax_id_type'] = $row['tax_id_type'] ?? null;
            $attributes['tax_id'] = $row['tax_id'] ?? null;
        }
        // On update, blank cells and absent columns never overwrite existing data.
        $client->fill($existing === null ? $attributes : array_filter($attributes, fn (mixed $v): bool => $v !== null));
        if ($existing === null) {
            $client->credit_days ??= 0;
            $client->setAttribute('credit_limit', $client->getAttribute('credit_limit') ?? '0.00');
        }
        if (filled($row['code'] ?? null)) {
            $client->code = (string) $row['code'];
        } elseif (! $client->exists && blank($client->code)) {
            $client->setAttribute('id', (int) DB::scalar("select nextval(pg_get_serial_sequence('clients', 'id'))"));
            $client->code = 'CLI-'.str_pad((string) $client->getKey(), 5, '0', STR_PAD_LEFT);
        }
        $client->save();

        return $client;
    }
}
