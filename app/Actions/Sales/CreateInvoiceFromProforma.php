<?php

namespace App\Actions\Sales;

use App\Enums\PaymentTerms;
use App\Enums\ProformaStatus;
use App\Exceptions\DocumentStateException;
use App\Models\Invoice;
use App\Models\Proforma;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;

class CreateInvoiceFromProforma
{
    public function __construct(private readonly SaveInvoice $saveInvoice) {}

    /** @param array<int|string, string> $quantities quantity to invoice per proforma line id (validated by InvoiceFromProformaRequest) */
    public function handle(Proforma $proforma, array $quantities, User $actor): Invoice
    {
        return DB::transaction(function () use ($proforma, $quantities, $actor): Invoice {
            $proforma = Proforma::query()->whereKey($proforma->id)->lockForUpdate()->with(['lines', 'client', 'currency'])->firstOrFail();
            if ($proforma->status !== ProformaStatus::Approved) {
                throw new DocumentStateException('Solo se pueden facturar proformas aprobadas');
            }
            $warehouse = Warehouse::default() ?? throw new DocumentStateException('No hay un almacén predeterminado');

            $lines = [];
            foreach ($proforma->lines as $line) {
                $quantity = Decimal::qty($quantities[$line->id] ?? '0');
                if (Decimal::cmp($quantity, '0') <= 0) {
                    continue;
                }
                $remaining = $line->pendingQuantity();
                if (Decimal::cmp($quantity, $remaining) > 0) {
                    throw new DocumentStateException("Cantidad pendiente: {$remaining}.");
                }
                $lines[] = [
                    'product_id' => $line->product_id, 'description' => $line->description, 'quantity' => $quantity,
                    'unit_price' => $line->unit_price, 'discount_percent' => $line->discount_percent, 'proforma_line_id' => $line->id,
                ];
            }
            if ($lines === []) {
                throw new DocumentStateException('Indica al menos una cantidad a facturar.');
            }

            $invoice = $this->saveInvoice->handle([
                'client_id' => $proforma->client_id, 'salesperson_id' => $proforma->salesperson_id, 'warehouse_id' => $warehouse->id,
                'currency_id' => $proforma->currency_id, 'issue_date' => now()->toDateString(), 'payment_terms' => PaymentTerms::Credit->value,
                'credit_days' => $proforma->client->credit_days, 'notes' => "Desde proforma {$proforma->number}", 'lines' => $lines,
            ], $actor);
            $invoice->forceFill(['proforma_id' => $proforma->id])->save();

            activity('invoices')->causedBy($actor)->performedOn($invoice)->event('created_from_proforma')
                ->withProperties(['proforma_id' => $proforma->id, 'proforma_number' => $proforma->number])
                ->log("Borrador de factura creado desde {$proforma->number}");

            return $invoice;
        });
    }
}
