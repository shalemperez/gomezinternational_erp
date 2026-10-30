<?php

namespace App\Http\Requests\Catalog;

use App\Enums\TaxIdType;
use App\Enums\TaxpayerType;
use App\Http\Requests\Concerns\ValidatesDominicanTaxId;
use App\Support\FiscalId;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreClientRequest extends FormRequest
{
    use ValidatesDominicanTaxId;

    /** @var list<string> */
    protected array $booleans = ['is_active', 'tax_id_override'];

    public function authorize(): bool
    {
        return true; // permission enforced by controller middleware
    }

    protected function prepareForValidation(): void
    {
        foreach ($this->booleans as $field) {
            $this->merge([$field => $this->boolean($field)]);
        }

        $taxIdType = $this->input('tax_id_type');
        $taxId = FiscalId::normalize((string) $this->input('tax_id'));
        $email = $this->input('email');

        $this->merge([
            'tax_id' => $taxIdType === 'none' || $taxId === '' ? null : $taxId,
            'email' => is_string($email) && $email !== '' ? strtolower($email) : null,
            'contacts' => $this->input('contacts', []),
        ]);

        foreach (['salesperson_id', 'price_list_id', 'trade_name', 'address', 'city', 'phone', 'notes'] as $field) {
            if (in_array($this->input($field), ['', 'none'], true)) {
                $this->merge([$field => null]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:20', 'alpha_dash', 'unique:clients,code'],
            'business_name' => ['required', 'string', 'max:200'],
            'trade_name' => ['nullable', 'string', 'max:200'],
            'tax_id_type' => ['required', Rule::enum(TaxIdType::class)],
            'tax_id' => ['required_unless:tax_id_type,none', 'nullable', 'string'],
            'taxpayer_type' => ['required', Rule::enum(TaxpayerType::class)],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'salesperson_id' => ['nullable', 'integer', 'exists:salespeople,id'],
            'credit_days' => ['required', 'integer', 'between:0,365'],
            'credit_limit' => ['required', 'numeric', 'min:0', 'max:999999999999.99', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'price_list_id' => ['nullable', 'integer', 'exists:price_lists,id'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'contacts' => ['nullable', 'array'],
            'contacts.*.name' => ['required', 'string', 'max:120'],
            'contacts.*.position' => ['nullable', 'string', 'max:100'],
            'contacts.*.email' => ['nullable', 'email', 'max:255'],
            'contacts.*.phone' => ['nullable', 'string', 'max:30'],
            'contacts.*.id' => ['nullable', 'integer'],
            'contacts.*.is_primary' => ['nullable', 'boolean'],
            'tax_id_override' => ['boolean'],
            'tax_id_override_reason' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return array_merge($this->taxIdMessages(), [
            'contacts.*.id.exists' => 'El contacto no pertenece a este registro.',
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $this->addTaxIdValidation($validator, 'clients', $this->route('client'));
        $validator->after(function (Validator $v): void {
            $primary = collect($this->array('contacts'))->filter(fn ($c) => filter_var($c['is_primary'] ?? false, FILTER_VALIDATE_BOOL))->count();
            if ($this->input('taxpayer_type') === 'juridica' && $this->input('tax_id_type') !== 'rnc') {
                $v->errors()->add('tax_id_type', 'Una persona jurídica debe identificarse con RNC.');
            }
            if ($primary > 1) {
                $v->errors()->add('contacts', 'Solo un contacto puede ser el principal.');
            }
        });
    }
}
