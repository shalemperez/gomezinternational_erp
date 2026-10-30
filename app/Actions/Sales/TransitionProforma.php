<?php

namespace App\Actions\Sales;

use App\Enums\ProformaStatus;
use App\Exceptions\DocumentStateException;
use App\Models\Proforma;
use App\Models\User;

class TransitionProforma
{
    public function markSent(Proforma $proforma, User $user): Proforma
    {
        return $this->transition($proforma, $user, [ProformaStatus::Draft], ['status' => ProformaStatus::Sent, 'sent_at' => now()], 'sent', null, 'marcada como enviada');
    }

    public function approve(Proforma $proforma, User $user, ?string $note): Proforma
    {
        return $this->transition($proforma, $user, [ProformaStatus::Draft, ProformaStatus::Sent], [
            'status' => ProformaStatus::Approved, 'approved_at' => now(), 'approved_by' => $user->id, 'approval_note' => $note,
        ], 'approved', $note, 'aprobada');
    }

    public function reject(Proforma $proforma, User $user, ?string $note): Proforma
    {
        return $this->transition($proforma, $user, [ProformaStatus::Draft, ProformaStatus::Sent], [
            'status' => ProformaStatus::Rejected, 'rejected_at' => now(), 'rejection_note' => $note,
        ], 'rejected', $note, 'rechazada');
    }

    /**
     * @param  list<ProformaStatus>  $allowedFrom
     * @param  array<string, mixed>  $changes
     */
    private function transition(Proforma $proforma, User $user, array $allowedFrom, array $changes, string $event, ?string $note, string $label): Proforma
    {
        if (! in_array($proforma->status, $allowedFrom, true)) {
            throw new DocumentStateException("La proforma está {$proforma->status->label()} y no admite esta acción");
        }

        $from = $proforma->status->value;
        $proforma->forceFill($changes)->save();

        activity('proformas')->causedBy($user)->performedOn($proforma)->event($event)
            ->withProperties(['from' => $from, 'to' => $proforma->status->value, 'note' => $note])
            ->log("Proforma {$proforma->number} {$label}");

        return $proforma;
    }
}
