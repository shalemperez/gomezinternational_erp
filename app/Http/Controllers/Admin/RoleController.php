<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\SyncRolePermissions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateRolePermissionsRequest;
use App\Support\PermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    #[Middleware('permission:admin.roles.view')]
    public function index(Request $request): Response
    {
        $roles = collect(PermissionCatalog::ROLES)->map(function (string $label, string $name): array {
            $permissions = $name === PermissionCatalog::SUPER_ADMIN
                ? PermissionCatalog::names()
                : Role::findByName($name)->permissions->pluck('name')->values()->all();

            return ['name' => $name, 'label' => $label, 'permissions' => $permissions];
        })->values()->all();

        $permissions = collect(PermissionCatalog::permissions())
            ->map(fn (array $def, string $name) => ['name' => $name, 'label' => $def['label'], 'module' => $def['module']])
            ->values()->all();

        return Inertia::render('admin/roles/index', [
            'roles' => $roles,
            'modules' => PermissionCatalog::modules(),
            'permissions' => $permissions,
            'isSuperAdmin' => $request->user()->isSuperAdmin(),
        ]);
    }

    #[Middleware('permission:admin.roles.update')]
    public function update(UpdateRolePermissionsRequest $request, Role $role, SyncRolePermissions $action): RedirectResponse
    {
        $action->handle($role, $request->validated('permissions'), $request->user());
        $label = PermissionCatalog::ROLES[$role->name] ?? $role->name;

        return redirect()->route('admin.roles.index')->with('success', "Permisos del rol {$label} actualizados");
    }
}
