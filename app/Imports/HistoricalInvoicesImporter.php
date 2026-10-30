<?php

namespace App\Imports;

use App\Actions\Sales\RegisterHistoricalInvoice;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Salesperson;
use App\Support\FiscalId;
use App\Support\Money\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class HistoricalInvoicesImporter extends BaseImporter
{
    public static function type(): string
    {
        return 'historical_invoices';
    }

    public function label(): string
    {
        return 'Facturas históricas (saldos abiertos)';
    }

    public function permission(): string
    {
        return 'sales.historical-invoices.import';
    }

    public function columns(): array
    {
        return [
            ['key' => 'ncf', 'label' => 'NCF', 'required' => true, 'example' => 'B0100000123'],
            ['key' => 'client', 'label' => 'Cliente (RNC, cédula o código)', 'required' => true, 'example' => '1-01-85004-3'],
            ['key' => 'salesperson_code', 'label' => 'Vendedor (código)', 'required' => false, 'example' => 'VEN-01'],
            ['key' => 'issue_date', 'label' => 'Fecha (AAAA-MM-DD)', 'required' => true, 'example' => '2026-09-15'],
            ['key' => 'due_date', 'label' => 'Vencimiento (AAAA-MM-DD)', 'required' => false, 'example' => '2026-10-15'],
            ['key' => 'currency_code', 'label' => 'Moneda', 'required' => true, 'example' => 'DOP'],
            ['key' => 'taxable_total', 'label' => 'Base imponible', 'required' => true, 'example' => '10000.00'],
            ['key' => 'itbis_total', 'label' => 'ITBIS', 'required' => true, 'example' => '1800.00'],
            ['key' => 'total', 'label' => 'Total', 'required' => true, 'example' => '11800.00'],
            ['key' => 'paid_amount', 'label' => 'Pagado', 'required' => false, 'example' => '0'],
            ['key' => 'notes', 'label' => 'Notas', 'required' => false, 'example' => ''],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function normalize(array $row, array $meta): array
    {
        $row['ncf'] = isset($row['ncf']) ? strtoupper($row['ncf']) : null;
        if (isset($row['client'])) {
            $digits = FiscalId::normalize($row['client']);
            $row['client'] = $digits !== '' && preg_match('/^[\d\s-]+$/', $row['client']) === 1 ? $digits : strtoupper($row['client']);
        }
        $row['currency_code'] = isset($row['currency_code']) ? strtoupper($row['currency_code']) : null;

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function rules(array $row, array $meta): array
    {
        return [
            'ncf' => ['required', 'regex:/^B(01|02|14|15)\d{8}$/', Rule::unique('invoices', 'ncf')],
            'client' => ['required', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($this->findClient((string) $value) === null) {
                    $fail('Cliente no encontrado.');
                }
            }],
            'salesperson_code' => [
                Rule::when(filled($row['salesperson_code'] ?? null), ['string', Rule::exists('salespeople', 'code')->where('is_active', true)]),
                function (string $attribute, mixed $value, \Closure $fail) use ($row): void {
                    $client = $this->findClient($row['client'] ?? null);
                    if (blank($value) && $client !== null && $client->salesperson_id === null) {
                        $fail('El cliente no tiene vendedor asignado; indica el código del vendedor.');
                    }
                },
            ],
            'issue_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:issue_date'],
            'currency_code' => ['required', 'string', 'exists:currencies,code'],
            'taxable_total' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'itbis_total' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'total' => ['required', 'numeric', 'gt:0', 'max:999999999999.99', function (string $attribute, mixed $value, \Closure $fail) use ($row): void {
                $taxable = $row['taxable_total'] ?? null;
                $itbis = $row['itbis_total'] ?? null;
                if (is_numeric($value) && is_numeric($taxable) && is_numeric($itbis) && Decimal::cmp((string) $value, Decimal::sum([(string) $taxable, (string) $itbis])) < 0) {
                    $fail('El total no puede ser menor que la base imponible más el ITBIS.');
                }
            }],
            'paid_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99', function (string $attribute, mixed $value, \Closure $fail) use ($row): void {
                $total = $row['total'] ?? null;
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
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $meta
     */
    public function naturalKey(array $row, array $meta): ?string
    {
        return $row['ncf'] ?: null;
    }

    /**
     * Every row is a new invoice; a repeated NCF is refused by the rules, so there is never an existing record to update.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $meta
     */
    public function findExisting(array $row, array $meta): ?Model
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $meta
     */
    public function persist(array $row, ?Model $existing, array $meta): Model
    {
        $salespersonId = filled($row['salesperson_code'] ?? null) ? Salesperson::query()->where('code', $row['salesperson_code'])->value('id') : null;

        return app(RegisterHistoricalInvoice::class)->handle([
            'ncf' => $row['ncf'],
            'client_id' => $this->findClient((string) $row['client'])?->id,
            'salesperson_id' => $salespersonId,
            'issue_date' => $row['issue_date'],
            'due_date' => $row['due_date'],
            'currency_id' => Currency::query()->where('code', $row['currency_code'])->value('id'),
            'taxable_total' => $row['taxable_total'],
            'itbis_total' => $row['itbis_total'],
            'total' => $row['total'],
            'paid_amount' => $row['paid_amount'],
            'notes' => $row['notes'],
        ], auth()->user());
    }

    /** Resolves the client by RNC/cédula or by code (inactive clients included: old invoices may belong to them). */
    private function findClient(?string $reference): ?Client
    {
        if ($reference === null || $reference === '') {
            return null;
        }

        return Client::query()->where('tax_id', $reference)->orWhere('code', $reference)->first();
    }
}
