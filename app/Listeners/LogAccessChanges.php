<?php

namespace App\Listeners;

use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Events\PermissionAttachedEvent;
use Spatie\Permission\Events\PermissionDetachedEvent;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class LogAccessChanges
{
    public function handle(RoleAttachedEvent|RoleDetachedEvent|PermissionAttachedEvent|PermissionDetachedEvent $event): void
    {
        if ($event instanceof RoleAttachedEvent) {
            [$verb, $label, $isRole, $given] = ['role_assigned', 'Rol asignado', true, $event->rolesOrIds];
        } elseif ($event instanceof RoleDetachedEvent) {
            [$verb, $label, $isRole, $given] = ['role_removed', 'Rol quitado', true, $event->rolesOrIds];
        } elseif ($event instanceof PermissionAttachedEvent) {
            [$verb, $label, $isRole, $given] = ['permission_granted', 'Permiso otorgado', false, $event->permissionsOrIds];
        } else {
            [$verb, $label, $isRole, $given] = ['permission_revoked', 'Permiso revocado', false, $event->permissionsOrIds];
        }

        $names = collect(is_iterable($given) ? $given : [$given])
            ->flatten()
            ->map(function (mixed $item) use ($isRole): string {
                if ($item instanceof Model) {
                    return (string) $item->getAttribute('name');
                }
                if (is_numeric($item)) {
                    $model = $isRole
                        ? Role::query()->whereKey((int) $item)->first()
                        : Permission::query()->whereKey((int) $item)->first();

                    return $model !== null ? (string) $model->getAttribute('name') : (string) $item;
                }

                return is_scalar($item) ? (string) $item : '';
            })
            ->unique()->values()->all();

        activity('access')
            ->performedOn($event->model)
            ->event($verb)
            ->withProperties(['names' => $names])
            ->log($label.': '.implode(', ', $names));
    }
}
