<?php

namespace App\Http\Requests\Admin;

use App\Rules\DominicanTaxId;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'company.name' => ['required', 'string', 'max:120'],
            'company.legal_name' => ['nullable', 'string', 'max:200'],
            'company.rnc' => ['nullable', 'string', new DominicanTaxId('rnc')],
            'company.address' => ['nullable', 'string', 'max:255'],
            'company.phone' => ['nullable', 'string', 'max:30'],
            'company.email' => ['nullable', 'email', 'max:255'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'exchange_rates.source' => ['required', Rule::in(['manual', 'http_json'])],
            // Laravel's `url` rule rejects the {currency}/{date} placeholders, hence the regex.
            'exchange_rates.http_json.url' => ['nullable', 'string', 'max:500', 'required_if:exchange_rates.source,http_json', 'regex:/^https?:\/\/\S+$/i'],
            'exchange_rates.http_json.json_path' => ['nullable', 'string', 'max:100', 'required_if:exchange_rates.source,http_json'],
            'fiscal.consumo_id_threshold' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'sales.proforma_validity_days' => ['required', 'integer', 'between:1,365'],
            'inventory.expiring_days' => ['required', 'integer', 'between:1,365'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'company.name' => 'el nombre', 'company.legal_name' => 'la razón social', 'company.rnc' => 'el RNC', 'company.address' => 'la dirección',
            'company.phone' => 'el teléfono', 'company.email' => 'el correo electrónico', 'logo' => 'el logo', 'exchange_rates.source' => 'la fuente',
            'exchange_rates.http_json.url' => 'la URL', 'exchange_rates.http_json.json_path' => 'la ruta JSON',
            'fiscal.consumo_id_threshold' => 'el umbral de facturas de consumo', 'sales.proforma_validity_days' => 'los días de validez de proformas',
            'inventory.expiring_days' => 'los días de alerta de vencimiento',
        ];
    }
}
