<?php

namespace App\Actions\Admin;

use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class SyncRolePermissions
{
    private const TECHNICAL = ['admin.system.manage', 'admin.users.impersonate'];

    /**
     * @param  array<int|string, string>  $permissionNames
     * @return array{added: list<string>, removed: list<string>}
     */
    public function handle(Role $role, array $permissionNames, User $actor): array
    {
        if ($role->name === PermissionCatalog::SUPER_ADMIN) {
            throw new AuthorizationException('El rol Super Admin no se puede modificar.');
        }
        if (! $actor->isSuperAdmin() && array_intersect(self::TECHNICAL, $permissionNames) !== []) {
            throw new AuthorizationException('Solo un Super Admin puede otorgar permisos técnicos.');
        }

        $before = $role->permissions->pluck('name')->all();
        $after = array_values($permissionNames);
        $added = array_values(array_diff($after, $before));

        if (! $actor->isSuperAdmin()) {
            $actorPermissions = $actor->getAllPermissions()->pluck('name')->all();
            if (array_diff($added, $actorPermissions) !== []) {
                throw new AuthorizationException('No puedes otorgar permisos que tú no tienes.');
            }
        }

        $role->syncPermissions($permissionNames);
        $diff = ['added' => $added, 'removed' => array_values(array_diff($before, $after))];

        activity('roles')->causedBy($actor)->performedOn($role)->event('permissions_updated')->withProperties($diff)->log("Permisos del rol {$role->name} actualizados");
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $diff;
    }
}
