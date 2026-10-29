<?php

use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

test('index requires permission and lists roles with modules', function () {
    actingAsRole('accounting');
    $this->get(route('admin.roles.index'))->assertForbidden();

    actingAsRole('owner');
    $this->get(route('admin.roles.index'))->assertOk()->assertInertia(fn ($p) => $p->component('admin/roles/index')->has('roles', 6)->has('modules')->has('permissions'));
});

test('owner updates permissions of a role and the change is logged', function () {
    actingAsRole('owner');

    $this->put(route('admin.roles.update', 'sales'), ['permissions' => ['catalog.clients.view', 'catalog.brands.view']])
        ->assertRedirect(route('admin.roles.index'))->assertSessionHas('success', 'Permisos del rol Ventas actualizados');

    $role = Role::findByName('sales');
    expect($role->permissions->pluck('name')->sort()->values()->all())->toBe(['catalog.brands.view', 'catalog.clients.view']);
    $activity = Activity::inLog('roles')->where('event', 'permissions_updated')->latest('id')->firstOrFail();
    expect($activity->properties['removed'])->toContain('catalog.products.view');
});

test('super admin role cannot be modified', function () {
    actingAsRole('owner');
    $this->put(route('admin.roles.update', 'super-admin'), ['permissions' => []])->assertForbidden();
});

test('only a super admin can grant technical permissions', function () {
    actingAsRole('owner');
    $this->put(route('admin.roles.update', 'management'), ['permissions' => ['admin.system.manage']])->assertForbidden();
    $this->put(route('admin.roles.update', 'management'), ['permissions' => ['admin.users.impersonate']])->assertForbidden();

    actingAsRole('super-admin');
    $this->put(route('admin.roles.update', 'management'), ['permissions' => ['admin.users.impersonate']])->assertRedirect(route('admin.roles.index'));
});

test('unknown permission names are rejected', function () {
    actingAsRole('owner');
    $this->put(route('admin.roles.update', 'sales'), ['permissions' => ['foo.bar']])->assertSessionHasErrors(['permissions.0']);
});

test('a non super admin cannot grant permissions they lack', function () {
    $actor = actingAsRole('sales');
    $actor->givePermissionTo('admin.roles.update');

    $this->put(route('admin.roles.update', 'warehouse'), ['permissions' => ['catalog.products.create']])->assertForbidden();
    expect(Role::findByName('warehouse')->hasPermissionTo('catalog.products.create'))->toBeFalse();
});
