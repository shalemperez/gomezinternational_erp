<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\NcfSequenceAlert;
use App\Services\Fiscal\SequenceAlerts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Notification;

#[Tries(1)]
class CheckNcfSequences implements ShouldQueue
{
    use Queueable;

    public function handle(SequenceAlerts $alerts): void
    {
        $list = $alerts->current();
        activity('ncf_sequences')->event('alerts_checked')->withProperties(['alerts' => $list])->log('Revisión de secuencias NCF: '.count($list).' alertas');
        if ($list === []) {
            return;
        }
        $users = User::query()->where('is_active', true)->get()->filter(fn (User $u) => $u->can('fiscal.sequences.manage'));
        Notification::send($users, new NcfSequenceAlert($list));
    }
}
