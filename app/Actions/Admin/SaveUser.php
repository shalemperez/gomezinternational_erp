<?php

namespace App\Actions\Admin;

use App\Models\User;
use App\Notifications\UserInvited;
use App\Support\PermissionCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class SaveUser
{
    /** @param array{name: string, email: string, roles: list<string>, is_active?: bool} $data */
    public function handle(array $data, User $actor, ?User $user = null): User
    {
        $roles = array_values(array_unique($data['roles']));
        $wantsSuperAdmin = in_array(PermissionCatalog::SUPER_ADMIN, $roles, true);
        $isActive = $data['is_active'] ?? true;

        if ($wantsSuperAdmin && ! $actor->isSuperAdmin()) {
            throw new AuthorizationException('Solo un Super Admin puede asignar el rol Super Admin.');
        }
        if ($user !== null) {
            if ($user->isSuperAdmin() && ! $actor->isSuperAdmin()) {
                throw new AuthorizationException('Solo un Super Admin puede editar a otro Super Admin.');
            }
            if ($user->is($actor) && $user->isSuperAdmin() && ! $wantsSuperAdmin) {
                throw new AuthorizationException('No puedes quitarte tu propio rol de Super Admin.');
            }
            if ($user->is($actor) && ! $isActive) {
                throw new AuthorizationException('No puedes desactivar tu propia cuenta.');
            }
            if ($user->is_active !== $isActive && ! $actor->can('admin.users.deactivate')) {
                throw new AuthorizationException('No tienes permiso para activar o desactivar usuarios.');
            }
        }

        return DB::transaction(function () use ($data, $roles, $isActive, $user, $actor): User {
            $isNew = $user === null;
            $activeChanged = $user !== null && $user->is_active !== $isActive;
            $user ??= new User;
            $user->fill(['name' => $data['name'], 'email' => $data['email'], 'is_active' => $isActive])->save();
            $user->syncRoles($roles);
            if ($activeChanged) {
                activity('users')->causedBy($actor)->performedOn($user)->event($isActive ? 'activated' : 'deactivated')
                    ->log($isActive ? "Usuario {$user->name} activado" : "Usuario {$user->name} desactivado");
            }
            if ($isNew) {
                $user->notify(new UserInvited);
            }

            return $user;
        });
    }
}
