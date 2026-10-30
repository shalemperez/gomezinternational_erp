<?php

namespace App\Actions\Catalog;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ApplyTaxIdOverride
{
    /**
     * Sets (or clears) the override columns on an already-saved client/supplier and writes the audit row.
     *
     * @param  array<string, mixed>  $data  validated request data (keys tax_id_override, tax_id_override_reason)
     */
    public function handle(Model $record, array $data, User $actor, string $logName): void
    {
        $override = (bool) ($data['tax_id_override'] ?? false);
        if ($override) {
            $reason = (string) $data['tax_id_override_reason'];
            $record->forceFill(['tax_id_validation_overridden' => true, 'tax_id_override_reason' => $reason, 'tax_id_override_by' => $actor->id, 'tax_id_override_at' => now()])->save();
            activity($logName)->causedBy($actor)->performedOn($record)->event('tax_id_validation_overridden')
                ->withProperties(['tax_id' => $record->getAttribute('tax_id'), 'reason' => $reason])
                ->log('Identificación fiscal forzada: '.$reason);

            return;
        }
        if ($record->getAttribute('tax_id_validation_overridden') && $record->wasChanged('tax_id')) {
            $record->forceFill(['tax_id_validation_overridden' => false, 'tax_id_override_reason' => null, 'tax_id_override_by' => null, 'tax_id_override_at' => null])->save();
        }
    }
}
