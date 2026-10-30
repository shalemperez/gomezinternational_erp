<?php

namespace App\Http\Requests\Sales;

use App\Models\Client;
use App\Support\Money\Decimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreHistoricalInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission enforced by controller middleware
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ncf' => ['required', 'string', 'regex:/^B(01|02|14|15)\d{8}$/', Rule::unique('invoices', 'ncf')],
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->where('is_active', true)],
            'salesperson_id' => ['nullable', 'integer', Rule::exists('salespeople', 'id')->where('is_active', true)],
            'issue_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:issue_date'],
            'currency_id' => ['required', 'integer', Rule::exists('currencies', 'id')->where('is_active', true)],
            'taxable_total' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'itbis_total' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'total' => ['required', 'numeric', 'gt:0', 'max:999999999999.99', function (string $attribute, mixed $value, \Closure $fail): void {
                $taxable = $this->input('taxable_total');
                $itbis = $this->input('itbis_total');
                if (is_numeric($value) && is_numeric($taxable) && is_numeric($itbis) && Decimal::cmp((string) $value, Decimal::sum([(string) $taxable, (string) $itbis])) < 0) {
                    $fail('El total no puede ser menor que la base imponible más el ITBIS.');
                }
            }],
            'paid_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99', function (string $attribute, mixed $value, \Closure $fail): void {
                $total = $this->input('total');
                if (is_numeric($value) && is_numeric($total) && Decimal::cmp((string) $value, (string) $total) > 0) {
                    $fail('El pago no puede superar el total.');
                }
            }],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'ncf.unique' => 'El NCF ya está registrado.',
            'ncf.regex' => 'El NCF debe ser B01/B02/B14/B15 seguido de 8 dígitos.',
            'client_id.exists' => 'El cliente no existe o está inactivo.',
            'salesperson_id.exists' => 'El vendedor no existe o está inactivo.',
            'currency_id.exists' => 'La moneda no existe o está inactiva.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $clientId = $this->integer('client_id');
            if ($clientId <= 0 || $validator->errors()->has('client_id') || $this->filled('salesperson_id')) {
                return;
            }
            $client = Client::query()->whereKey($clientId)->first();
            if ($client !== null && $client->salesperson_id === null) {
                $validator->errors()->add('salesperson_id', 'El cliente no tiene vendedor asignado; selecciona el vendedor.');
            }
        });
    }
}
