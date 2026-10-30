<?php

namespace App\Http\Requests\Sales\Concerns;

use App\Models\Invoice;
use App\Support\Money\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;

trait ValidatesReceiptApplications
{
    /** @return array<string, mixed> */
    protected function applicationRules(): array
    {
        return [
            'applications.*.invoice_id' => ['required', 'integer', 'exists:invoices,id', 'distinct'],
            'applications.*.amount' => ['required', 'numeric', 'gt:0', 'max:999999999999.99'],
        ];
    }

    /** @return Collection<int, Invoice> the applied invoices keyed by id */
    protected function applicationInvoices(): Collection
    {
        return Invoice::query()->whereIn('id', $this->applicationInvoiceIds())->get()->keyBy('id');
    }

    /**
     * Checks each application against its invoice (open, same client, same currency, enough balance).
     *
     * @param  Collection<int, Invoice>  $invoices
     * @return string Σ of the application amounts
     */
    protected function checkApplications(Validator $validator, Collection $invoices, int $clientId, int $currencyId): string
    {
        $sum = '0';
        foreach (array_keys($this->array('applications')) as $index) {
            $amount = $this->string("applications.{$index}.amount")->toString();
            $sum = Decimal::sum([$sum, $amount]);
            $invoice = $invoices->get($this->integer("applications.{$index}.invoice_id"));
            if ($invoice === null) {
                continue;
            }
            if (! $invoice->isOpen()) {
                $validator->errors()->add("applications.{$index}.invoice_id", "La factura {$invoice->number} no está pendiente de cobro.");
            } elseif ((int) $invoice->client_id !== $clientId) {
                $validator->errors()->add("applications.{$index}.invoice_id", "La factura {$invoice->number} es de otro cliente.");
            } elseif ((int) $invoice->currency_id !== $currencyId) {
                $validator->errors()->add("applications.{$index}.invoice_id", "La factura {$invoice->number} está en otra moneda.");
            } elseif (Decimal::cmp($amount, $invoice->balance) > 0) {
                $validator->errors()->add("applications.{$index}.amount", "La aplicación supera el saldo de la factura {$invoice->number} ({$invoice->balance}).");
            }
        }

        return $sum;
    }

    /** @return list<int> */
    private function applicationInvoiceIds(): array
    {
        $ids = [];
        foreach (array_keys($this->array('applications')) as $index) {
            $ids[] = $this->integer("applications.{$index}.invoice_id");
        }

        return array_values(array_unique($ids));
    }
}
