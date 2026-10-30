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

test('a role that already holds a technical permission can still be edited by the owner', function () {
    actingAsRole('owner');
    Role::findByName('owner')->givePermissionTo('admin.users.impersonate');
    $current = Role::findByName('sales')->permissions->pluck('name')->all();
    Role::findByName('sales')->givePermissionTo('admin.users.impersonate');

    $this->put(route('admin.roles.update', 'sales'), ['permissions' => [...$current, 'admin.users.impersonate', 'catalog.brands.create']])
        ->assertRedirect(route('admin.roles.index'));
    expect(Role::findByName('sales')->hasPermissionTo('admin.users.impersonate'))->toBeTrue()
        ->and(Role::findByName('sales')->hasPermissionTo('catalog.brands.create'))->toBeTrue();
});

test('only a super admin can remove a technical permission', function () {
    actingAsRole('owner');
    Role::findByName('sales')->givePermissionTo('admin.users.impersonate');
    $without = Role::findByName('sales')->permissions->pluck('name')->reject(fn ($p) => $p === 'admin.users.impersonate')->values()->all();

    $this->put(route('admin.roles.update', 'sales'), ['permissions' => $without])->assertForbidden();
    expect(Role::findByName('sales')->hasPermissionTo('admin.users.impersonate'))->toBeTrue();
});

test('saving without changes writes no audit row', function () {
    actingAsRole('owner');
    $current = Role::findByName('warehouse')->permissions->pluck('name')->all();
    $before = Activity::inLog('roles')->count();

    $this->put(route('admin.roles.update', 'warehouse'), ['permissions' => $current])->assertRedirect(route('admin.roles.index'));
    expect(Activity::inLog('roles')->count())->toBe($before);
});
