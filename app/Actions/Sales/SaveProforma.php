<?php

namespace App\Actions\Sales;

use App\Enums\DocumentKind;
use App\Enums\ProformaStatus;
use App\Exceptions\DocumentStateException;
use App\Models\Proforma;
use App\Models\User;
use App\Services\Documents\DocumentCurrency;
use App\Services\Documents\DocumentLines;
use App\Services\Documents\DocumentNumbering;
use App\Services\Documents\DocumentTotalsCalculator;
use App\Services\Documents\SalespersonResolver;
use App\Support\Documents\LineInput;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class SaveProforma
{
    public function __construct(private readonly DocumentTotalsCalculator $calculator, private readonly DocumentNumbering $numbering) {}

    /** @param array<string, mixed> $data validated StoreProformaRequest data */
    public function handle(array $data, User $actor, ?Proforma $proforma = null): Proforma
    {
        return DB::transaction(function () use ($data, $actor, $proforma): Proforma {
            if ($proforma !== null && ! $proforma->isDraft()) {
                throw new DocumentStateException('Solo se pueden editar proformas en borrador');
            }

            $salespersonId = SalespersonResolver::resolve($actor, ($data['salesperson_id'] ?? null) !== null ? (int) $data['salesperson_id'] : null, Proforma::VIEW_ALL_PERMISSION);
            ['exchange_rate' => $rate] = DocumentCurrency::resolve((int) $data['currency_id'], CarbonImmutable::parse($data['issue_date']));
            $computed = $this->calculator->compute(array_values(array_map(LineInput::fromArray(...), $data['lines'])));

            $attributes = [
                'client_id' => (int) $data['client_id'],
                'salesperson_id' => $salespersonId,
                'currency_id' => (int) $data['currency_id'],
                'exchange_rate' => $rate,
                'issue_date' => $data['issue_date'],
                'valid_until' => $data['valid_until'],
                'price_list_id' => $data['price_list_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                ...DocumentLines::totals($computed, $rate),
            ];

            if ($proforma === null) {
                $proforma = Proforma::create([
                    ...$attributes,
                    'number' => $this->numbering->next(DocumentKind::Proforma),
                    'status' => ProformaStatus::Draft,
                    'created_by' => $actor->id,
                ]);
            } else {
                $proforma->update($attributes);
            }

            DocumentLines::write($proforma, $computed);

            return $proforma->fresh();
        });
    }
}
