<?php

namespace App\Actions\Sales;

use App\Models\Proforma;
use App\Models\ProformaLine;
use App\Models\User;
use App\Services\Settings;
use Illuminate\Support\Facades\DB;

class DuplicateProforma
{
    public function __construct(private readonly SaveProforma $saveProforma, private readonly Settings $settings) {}

    public function handle(Proforma $source, User $actor): Proforma
    {
        return DB::transaction(function () use ($source, $actor): Proforma {
            $source->loadMissing('lines');

            // SaveProforma resolves the salesperson: view-all users keep the source one, others get their own.
            $copy = $this->saveProforma->handle([
                'client_id' => $source->client_id,
                'salesperson_id' => $source->salesperson_id,
                'currency_id' => $source->currency_id,
                'issue_date' => now()->toDateString(),
                'valid_until' => now()->addDays((int) $this->settings->get('sales.proforma_validity_days', 15))->toDateString(),
                'price_list_id' => $source->price_list_id,
                'notes' => $source->notes,
                'lines' => $source->lines->map(fn (ProformaLine $line) => [
                    'product_id' => $line->product_id,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unit_price,
                    'discount_percent' => $line->discount_percent,
                ])->values()->all(),
            ], $actor);

            activity('proformas')->causedBy($actor)->performedOn($copy)->event('duplicated')
                ->withProperties(['source_id' => $source->id, 'source_number' => $source->number])
                ->log("Proforma {$copy->number} creada a partir de {$source->number}");

            return $copy;
        });
    }
}
