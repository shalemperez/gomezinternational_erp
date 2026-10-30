<?php

namespace App\Actions\Inventory;

use App\Enums\DispatchStatus;
use App\Enums\DocumentKind;
use App\Enums\InvoiceStatus;
use App\Exceptions\DocumentStateException;
use App\Models\Dispatch;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Accounting\AccountingEventRecorder;
use App\Services\Documents\DocumentNumbering;
use App\Services\Inventory\InventoryService;
use App\Support\Inventory\StockLine;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;

class ConfirmDispatch
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly DocumentNumbering $documentNumbering,
        private readonly AccountingEventRecorder $events,
    ) {}

    public function handle(Dispatch $dispatch, User $actor, bool $expiredOverride = false, ?string $overrideReason = null): Dispatch
    {
        return DB::transaction(function () use ($dispatch, $actor, $expiredOverride, $overrideReason): Dispatch {
            $invoiceId = Dispatch::query()->whereKey($dispatch->id)->value('invoice_id');
            $invoice = Invoice::query()->whereKey($invoiceId)->lockForUpdate()->with('lines')->firstOrFail();
            $dispatch = Dispatch::query()->whereKey($dispatch->id)->lockForUpdate()->with(['lines.product', 'lines.lot'])->firstOrFail();
            if ($dispatch->status !== DispatchStatus::Draft) {
                throw new DocumentStateException("El conduce está {$dispatch->status->label()} y no admite esta acción");
            }

            if (! in_array($invoice->status, [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid, InvoiceStatus::Paid], true)) {
                throw new DocumentStateException('Solo se despachan facturas emitidas');
            }

            $expired = $dispatch->lines->filter(fn ($line) => $line->lot?->expires_on !== null && $line->lot->expires_on->toDateString() < $dispatch->dispatch_date->toDateString());
            if ($expired->isNotEmpty() && ! $expiredOverride) {
                $line = $expired->first();

                throw new DocumentStateException("El lote {$line->lot->code} de {$line->product->sku} venció el {$line->lot->expires_on->format('d/m/Y')}; un usuario autorizado debe aprobar el despacho de lotes vencidos.");
            }

            $number = $this->documentNumbering->next(DocumentKind::Dispatch);
            $totalCost = '0';
            $payloadLines = [];
            foreach ($dispatch->lines as $line) {
                $invoiceLine = $invoice->lines->firstWhere('id', $line->invoice_line_id);
                $quantity = Decimal::qty($line->quantity);
                $pending = $invoiceLine->remainingToDispatch();
                if (Decimal::cmp($quantity, $pending) > 0) {
                    throw new DocumentStateException("Cantidad pendiente de despacho: {$pending}.");
                }

                $movement = $this->inventory->dispatch(
                    new StockLine($line->product_id, $dispatch->warehouse_id, $quantity, null, $line->lot_id),
                    $dispatch, $actor, "Conduce {$number}",
                );
                $this->inventory->release($invoiceLine->reservations()->whereNull('released_at')->firstOrFail(), $quantity);

                $invoiceLine->forceFill([
                    'dispatched_quantity' => Decimal::qty(Decimal::of($invoiceLine->dispatched_quantity)->plus(Decimal::of($quantity))),
                    'dispatched_cost' => Decimal::sum([(string) $invoiceLine->dispatched_cost, (string) $movement->total_cost]),
                ])->save();
                $line->forceFill(['unit_cost' => $movement->unit_cost, 'total_cost' => $movement->total_cost])->save();
                $totalCost = Decimal::sum([$totalCost, (string) $movement->total_cost]);
                $payloadLines[] = [
                    'product_id' => $line->product_id, 'sku' => $line->product->sku, 'lot_id' => $line->lot_id, 'quantity' => $quantity,
                    'unit_cost' => $movement->unit_cost, 'total_cost' => $movement->total_cost,
                ];
            }

            $dispatch->forceFill([
                'number' => $number, 'status' => DispatchStatus::Confirmed, 'total_cost' => $totalCost, 'confirmed_at' => now(), 'confirmed_by' => $actor->id,
            ])->save();
            $invoice->refreshDispatchStatus();

            activity('dispatches')->causedBy($actor)->performedOn($dispatch)->event('confirmed')
                ->withProperties(['number' => $number, 'invoice_id' => $invoice->id, 'total_cost' => $totalCost])
                ->log("Conduce {$number} confirmado");
            if ($expired->isNotEmpty()) {
                activity('dispatches')->causedBy($actor)->performedOn($dispatch)->event('expired_lot_override')
                    ->withProperties(['number' => $number, 'lots' => $expired->map(fn ($line) => $line->lot->code)->values()->all(), 'reason' => $overrideReason])
                    ->log("Despacho de lotes vencidos autorizado en el conduce {$number}");
            }
            activity('invoices')->causedBy($actor)->performedOn($invoice)->event('dispatched')
                ->withProperties(['dispatch_id' => $dispatch->id, 'number' => $number])
                ->log("Conduce {$number} despachado de la factura {$invoice->number}");

            $this->events->record('dispatch_confirmed', $dispatch, [
                'number' => $number, 'invoice_id' => $invoice->id, 'invoice_number' => $invoice->number, 'ncf' => $invoice->ncf,
                'client_id' => $dispatch->client_id, 'dispatch_date' => $dispatch->dispatch_date->toDateString(), 'warehouse_id' => $dispatch->warehouse_id,
                'lines' => $payloadLines, 'total_cost' => $totalCost, 'currency' => 'DOP', 'exchange_rate' => '1.000000',
            ], $dispatch->dispatch_date);

            return $dispatch->fresh();
        });
    }
}
