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
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ClientsImporter extends BaseImporter
{
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
        $row['tax_id'] = $row['tax_id_type'] === 'none' || empty($row['tax_id']) ? null : FiscalId::normalize((string) $row['tax_id']);
        $row['currency_code'] = isset($row['currency_code']) ? strtoupper($row['currency_code']) : null;
        $row['email'] = isset($row['email']) ? strtolower($row['email']) : null;
        $row['credit_days'] = $row['credit_days'] ?? '0';
        $row['credit_limit'] = $row['credit_limit'] ?? '0';

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

        return [
            'code' => ['nullable', 'string', 'max:20', 'alpha_dash'],
            'business_name' => ['required', 'string', 'max:200'],
            'trade_name' => ['nullable', 'string', 'max:200'],
            'tax_id_type' => ['required', Rule::enum(TaxIdType::class)],
            'tax_id' => $taxIdRules,
            'taxpayer_type' => ['required', Rule::enum(TaxpayerType::class)],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'salesperson_code' => ['nullable', 'string', 'exists:salespeople,code'],
            'credit_days' => ['required', 'integer', 'between:0,365'],
            'credit_limit' => ['required', 'numeric', 'min:0'],
            'price_list_name' => ['nullable', 'string', 'exists:price_lists,name'],
            'currency_code' => ['required', 'string', 'exists:currencies,code'],
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
        $client->fill([
            'business_name' => $row['business_name'],
            'trade_name' => $row['trade_name'],
            'tax_id_type' => $row['tax_id_type'],
            'tax_id' => $row['tax_id'],
            'taxpayer_type' => $row['taxpayer_type'],
            'address' => $row['address'],
            'city' => $row['city'],
            'phone' => $row['phone'],
            'email' => $row['email'],
            'salesperson_id' => $row['salesperson_code'] ? Salesperson::query()->where('code', $row['salesperson_code'])->value('id') : $client->salesperson_id,
            'credit_days' => (int) $row['credit_days'],
            'credit_limit' => Decimal::money((string) $row['credit_limit']),
            'price_list_id' => $row['price_list_name'] ? PriceList::query()->where('name', $row['price_list_name'])->value('id') : $client->price_list_id,
            'currency_id' => Currency::query()->where('code', $row['currency_code'])->value('id'),
        ]);
        if (! empty($row['code'])) {
            $client->code = $row['code'];
        } elseif (blank($client->code)) {
            $client->code = 'TMP';
        }
        $client->save();
        if ($client->code === 'TMP') {
            $client->code = 'CLI-'.str_pad((string) $client->id, 5, '0', STR_PAD_LEFT);
            $client->save();
        }

        return $client;
    }
}
