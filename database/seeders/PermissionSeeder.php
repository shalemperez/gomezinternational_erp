<?php

namespace Database\Seeders;

use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function (): void {
            $roles = [];
            $newRoles = [];
            foreach (array_keys(PermissionCatalog::ROLES) as $name) {
                $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
                $roles[$name] = $role;
                if ($role->wasRecentlyCreated) {
                    $newRoles[] = $name;
                }
            }

            foreach (PermissionCatalog::permissions() as $name => $def) {
                $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
                foreach ($def['roles'] as $roleName) {
                    // Grant defaults when the permission is new or the role is new; never revoke admin edits.
                    if ($permission->wasRecentlyCreated || in_array($roleName, $newRoles, true)) {
                        $roles[$roleName]->givePermissionTo($permission);
                    }
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
