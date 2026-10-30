<?php

namespace App\Http\Requests\Sales;

use App\Enums\PaymentTerms;
use App\Http\Requests\Sales\Concerns\ValidatesDocumentLines;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreInvoiceRequest extends FormRequest
{
    use ValidatesDocumentLines;

    public function authorize(): bool
    {
        $invoice = $this->route('invoice');

        return $invoice === null || ($this->user()?->can('update', $invoice) ?? false);
    }

    protected function prepareForValidation(): void
    {
        foreach (['salesperson_id', 'notes'] as $field) {
            if ($this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->where('is_active', true)],
            'salesperson_id' => ['nullable', 'integer', Rule::exists('salespeople', 'id')->where('is_active', true)],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'currency_id' => ['required', 'integer', Rule::exists('currencies', 'id')->where('is_active', true)],
            'issue_date' => ['required', 'date_format:Y-m-d'],
            'payment_terms' => ['required', Rule::enum(PaymentTerms::class)],
            'credit_days' => ['required', 'integer', 'between:0,365'],
            'notes' => ['nullable', 'string', 'max:2000'],
            ...$this->lineRules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'client_id.exists' => 'El cliente no existe o está inactivo.',
            'salesperson_id.exists' => 'El vendedor no existe o está inactivo.',
            'warehouse_id.exists' => 'El almacén no existe o está inactivo.',
            'currency_id.exists' => 'La moneda no existe o está inactiva.',
            ...$this->lineMessages(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $clientId = $this->integer('client_id');
            $user = $this->user();
            if ($clientId > 0 && ! $validator->errors()->has('client_id') && $user instanceof User && ! Client::query()->visibleTo($user)->whereKey($clientId)->exists()) {
                $validator->errors()->add('client_id', 'No puedes crear documentos para clientes de otros vendedores.');
            }
        });
    }
}
