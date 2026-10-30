<?php

use App\Exceptions\AppendOnlyAuditException;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

test('model changes are logged with old and new values and request context', function () {
    $actor = actingAsRole('owner');
    $user = User::factory()->create(['name' => 'Antes']);
    $this->withHeader('User-Agent', 'PestAgent/1.0')->get(route('dashboard'))->assertOk();
    $user->update(['name' => 'Después']);

    $activity = Activity::inLog('users')->where('event', 'updated')->where('subject_id', $user->id)->latest('id')->firstOrFail();

    expect($activity->causer_id)->toBe($actor->id)
        ->and($activity->description)->toBe('Registro actualizado')
        ->and($activity->attribute_changes['attributes']['name'])->toBe('Después')
        ->and($activity->attribute_changes['old']['name'])->toBe('Antes')
        ->and(array_key_exists('updated_at', $activity->attribute_changes['attributes']))->toBeFalse()
        ->and($activity->request_id)->not->toBeNull()
        ->and($activity->ip)->toBe('127.0.0.1')
        ->and($activity->user_agent)->toBe('PestAgent/1.0');
});

test('deactivation gets an explicit description', function () {
    actingAsRole('owner');
    $user = User::factory()->create();
    $user->update(['is_active' => false]);

    expect(Activity::inLog('users')->where('subject_id', $user->id)->latest('id')->first()->description)->toBe('Registro desactivado');
});

test('activities created while impersonating carry the impersonator id', function () {
    $admin = actingAsRole('super-admin');
    $target = User::factory()->create();

    $this->actingAs($target)->withSession(['impersonator_id' => $admin->id])->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('auth.impersonator.id', $admin->id));

    session(['impersonator_id' => $admin->id]);
    activity('test')->causedBy($target)->log('prueba');

    expect(Activity::inLog('test')->latest('id')->first()->impersonator_id)->toBe($admin->id);
});

test('audit rows cannot be updated or deleted by the model', function () {
    activity('test')->log('x');
    $row = ActivityLog::latest('id')->firstOrFail();

    expect(fn () => $row->update(['description' => 'y']))->toThrow(AppendOnlyAuditException::class);
    expect(fn () => $row->delete())->toThrow(AppendOnlyAuditException::class);
});

test('audit rows cannot be updated or deleted at the database level', function () {
    activity('test')->log('x');
    $id = Activity::latest('id')->firstOrFail()->id;

    expect(fn () => DB::transaction(fn () => DB::table('activity_log')->where('id', $id)->update(['description' => 'y'])))->toThrow(QueryException::class, 'activity_log is append-only');
    expect(fn () => DB::transaction(fn () => DB::table('activity_log')->where('id', $id)->delete()))->toThrow(QueryException::class, 'activity_log is append-only');
});

test('role assignment and removal are logged', function () {
    actingAsRole('owner');
    $user = User::factory()->create();
    $user->assignRole('sales');
    $user->removeRole('sales');

    $assigned = Activity::inLog('access')->where('event', 'role_assigned')->where('subject_id', $user->id)->latest('id')->first();
    $removed = Activity::inLog('access')->where('event', 'role_removed')->where('subject_id', $user->id)->latest('id')->first();

    expect($assigned)->not->toBeNull()
        ->and($assigned->properties['names'])->toBe(['sales'])
        ->and($assigned->description)->toBe('Rol asignado: sales')
        ->and($removed)->not->toBeNull()
        ->and($removed->properties['names'])->toBe(['sales']);
});

test('responses carry a request id header', function () {
    actingAsRole('owner');
    $this->get(route('dashboard'))->assertHeader('X-Request-Id');
});

test('password and remember token are never written to the audit log', function () {
    $user = User::factory()->create(['remember_token' => 'tok-1']);
    $user->forceFill(['remember_token' => 'tok-2', 'password' => bcrypt('secret-pass')])->save();
    $user->forceFill(['name' => 'Otro Nombre', 'remember_token' => 'tok-3'])->save();

    $rows = Activity::query()->where('subject_type', $user->getMorphClass())->where('subject_id', $user->id)->get();
    expect($rows)->not->toBeEmpty();
    foreach ($rows as $row) {
        $json = json_encode($row->attribute_changes ?? $row->properties);
        expect($json)->not->toContain('remember_token')->not->toContain('password')->not->toContain('tok-');
    }
});

test('audit log cannot be truncated', function () {
    activity('test')->log('x');

    expect(fn () => DB::transaction(fn () => DB::statement('TRUNCATE activity_log')))->toThrow(QueryException::class, 'activity_log is append-only');
});

test('a user referenced as impersonator can be deleted', function () {
    $admin = User::factory()->create();
    $target = User::factory()->create();
    $this->actingAs($target)->withSession(['impersonator_id' => $admin->id])->get(route('dashboard'))->assertOk();
    $target->forceFill(['name' => 'Cambio'])->save();

    $admin->delete();

    expect(User::query()->whereKey($admin->id)->exists())->toBeFalse();
});

test('activity created outside an http request has console context', function () {
    $this->app->forgetInstance('request.id');
    activity('test')->log('desde consola');

    $row = Activity::latest('id')->firstOrFail();
    expect($row->user_agent)->toBe('console')
        ->and($row->ip)->toBeNull()
        ->and($row->request_id)->toBeNull()
        ->and($row->impersonator_id)->toBeNull();
});

test('permission grant and revoke on a role are logged', function () {
    $role = Role::findOrCreate('sales', 'web');
    $permission = Permission::findOrCreate('catalog.brands.view', 'web');
    $role->revokePermissionTo($permission);

    $role->givePermissionTo($permission);
    $role->revokePermissionTo($permission);

    expect(Activity::inLog('access')->where('event', 'permission_granted')->exists())->toBeTrue()
        ->and(Activity::inLog('access')->where('event', 'permission_revoked')->exists())->toBeTrue();
});
