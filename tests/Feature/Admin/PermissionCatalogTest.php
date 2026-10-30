<?php

use App\Actions\Admin\SyncSuperAdmins;
use App\Models\User;
use App\Support\PermissionCatalog;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

test('seeder creates all roles and permissions idempotently', function () {
    (new PermissionSeeder)->run();
    (new PermissionSeeder)->run();

    expect(Role::count())->toBe(6)
        ->and(Permission::count())->toBe(count(PermissionCatalog::names()))
        ->and(Role::findByName('owner')->hasPermissionTo('catalog.clients.create'))->toBeTrue()
        ->and(Role::findByName('sales')->hasPermissionTo('catalog.clients.view-all'))->toBeFalse()
        ->and(Role::findByName('sales')->hasPermissionTo('catalog.clients.export'))->toBeTrue()
        ->and(Role::findByName('warehouse')->hasPermissionTo('catalog.products.view'))->toBeTrue()
        ->and(Role::findByName('warehouse')->hasPermissionTo('catalog.products.export'))->toBeFalse()
        ->and(Role::findByName('accounting')->hasPermissionTo('catalog.salespeople.create'))->toBeTrue()
        ->and(Role::findByName('owner')->hasPermissionTo('admin.system.manage'))->toBeFalse()
        ->and(Role::findByName('owner')->hasPermissionTo('catalog.tax-ids.override-validation'))->toBeTrue()
        ->and(Role::findByName('accounting')->hasPermissionTo('catalog.tax-ids.override-validation'))->toBeFalse()
        ->and(Role::findByName('accounting')->hasPermissionTo('finance.accounts.export'))->toBeTrue()
        ->and(Role::findByName('accounting')->hasPermissionTo('admin.activity-log.export'))->toBeFalse()
        ->and(Role::findByName('owner')->hasPermissionTo('admin.activity-log.export'))->toBeTrue()
        ->and(Permission::count())->toBe(130)
        ->and(Role::findByName('warehouse')->hasPermissionTo('inventory.stock.export'))->toBeFalse()
        ->and(Role::findByName('sales')->hasPermissionTo('sales.invoices.issue'))->toBeTrue()
        ->and(Role::findByName('sales')->hasPermissionTo('sales.invoices.cancel'))->toBeFalse()
        ->and(Role::findByName('sales')->hasPermissionTo('sales.proformas.view-all'))->toBeFalse()
        ->and(Role::findByName('accounting')->hasPermissionTo('sales.receipts.cancel'))->toBeTrue()
        ->and(Role::findByName('warehouse')->hasPermissionTo('inventory.dispatches.confirm'))->toBeTrue()
        ->and(Role::findByName('warehouse')->hasPermissionTo('inventory.stock.view-costs'))->toBeFalse()
        ->and(Role::findByName('owner')->hasPermissionTo('fiscal.mode.manage'))->toBeTrue()
        ->and(Role::findByName('management')->hasPermissionTo('fiscal.mode.manage'))->toBeFalse()
        ->and(Role::findByName('management')->hasPermissionTo('inventory.dispatches.expired-override'))->toBeTrue()
        ->and(Role::findByName('warehouse')->hasPermissionTo('inventory.dispatches.expired-override'))->toBeFalse()
        ->and(Role::findByName('sales')->hasPermissionTo('fiscal.ecf.view'))->toBeTrue()
        ->and(Role::findByName('sales')->hasPermissionTo('fiscal.ecf.manage'))->toBeFalse()
        ->and(Role::findByName('accounting')->hasPermissionTo('fiscal.ecf.settings'))->toBeFalse();
});

test('management has no admin permissions', function () {
    (new PermissionSeeder)->run();

    $adminPermissions = Role::findByName('management')->permissions->filter(fn ($p) => str_starts_with($p->name, 'admin.'));

    expect($adminPermissions)->toHaveCount(0)
        ->and(Role::findByName('management')->hasPermissionTo('catalog.clients.create'))->toBeTrue();
});

test('seeder does not revoke permissions removed by an admin', function () {
    (new PermissionSeeder)->run();
    Role::findByName('owner')->revokePermissionTo('catalog.brands.delete');
    (new PermissionSeeder)->run();

    expect(Role::findByName('owner')->hasPermissionTo('catalog.brands.delete'))->toBeFalse();
});

test('super admin passes every gate check', function () {
    $user = actingAsRole('super-admin');

    expect($user->can('admin.system.manage'))->toBeTrue()
        ->and($user->can('anything.not.defined'))->toBeTrue();
});

test('sync super admins creates users from config', function () {
    (new PermissionSeeder)->run();
    config(['erp.super_admin_emails' => ['soporte@externo.test']]);

    expect(app(SyncSuperAdmins::class)->handle())->toBe(1)
        ->and(User::where('email', 'soporte@externo.test')->first()->hasRole('super-admin'))->toBeTrue()
        ->and(app(SyncSuperAdmins::class)->handle())->toBe(0);
});

test('shared props expose roles and permissions', function () {
    actingAsRole('sales');

    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->where('auth.roles', ['sales'])
        ->where('auth.impersonator', null)
        ->where('auth.gmail_connected', false)
        ->has('auth.permissions')
        ->where('flash.success', null));
});

test('seeder runs inside a database transaction', function () {
    Event::fake([TransactionBeginning::class]);
    DB::connection()->setEventDispatcher(app('events'));
    (new PermissionSeeder)->run();
    Event::assertDispatched(TransactionBeginning::class);
});
