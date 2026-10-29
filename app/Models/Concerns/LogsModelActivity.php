<?php

namespace App\Models\Concerns;

use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

trait LogsModelActivity
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['*'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName($this->getTable())
            ->setDescriptionForEvent(fn (string $event): string => $this->auditDescription($event));
    }

    protected function auditDescription(string $event): string
    {
        if ($event === 'updated' && $this->wasChanged('is_active')) {
            return $this->is_active ? 'Registro activado' : 'Registro desactivado';
        }

        return match ($event) {
            'created' => 'Registro creado',
            'updated' => 'Registro actualizado',
            'deleted' => 'Registro eliminado',
            default => "Registro {$event}",
        };
    }
}
