<?php

use App\Models\User;
use App\Notifications\UserInvited;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

test('index requires permission', function () {
    actingAsRole('sales');
    $this->get(route('admin.users.index'))->assertForbidden();

    actingAsRole('owner');
    $this->get(route('admin.users.index'))->assertOk()->assertInertia(fn ($p) => $p->component('admin/users/index')->has('rows.data'));
});

test('owner invites a user with roles and the invitation is sent', function () {
    Notification::fake();
    actingAsRole('owner');

    $this->post(route('admin.users.store'), validUserPayload(['email' => 'Luis@Empresa.test', 'roles' => ['sales', 'warehouse']]))
        ->assertRedirect(route('admin.users.index'))->assertSessionHas('success', 'Usuario invitado');

    $user = User::where('email', 'luis@empresa.test')->firstOrFail();
    expect($user->getRoleNames()->sort()->values()->all())->toBe(['sales', 'warehouse'])->and($user->is_active)->toBeTrue();
    Notification::assertSentTo($user, UserInvited::class);
    expect(Activity::inLog('users')->where('event', 'created')->where('subject_id', $user->id)->exists())->toBeTrue();
});

test('store validates fields', function () {
    actingAsRole('owner');
    User::factory()->create(['email' => 'dup@empresa.test']);

    $this->post(route('admin.users.store'), ['name' => '', 'email' => 'dup@empresa.test', 'roles' => ['nope']])
        ->assertSessionHasErrors(['name', 'email', 'roles.0']);
    $this->post(route('admin.users.store'), ['name' => 'X', 'email' => 'x@empresa.test'])->assertSessionHasErrors(['roles']);
});

test('owner cannot assign the super-admin role', function () {
    actingAsRole('owner');
    $this->post(route('admin.users.store'), validUserPayload(['roles' => ['super-admin']]))->assertForbidden();
});

test('owner cannot edit a super admin', function () {
    actingAsRole('owner');
    $target = User::factory()->create();
    $target->assignRole('super-admin');

    $this->put(route('admin.users.update', $target), validUserPayload(['email' => $target->email, 'roles' => ['owner'], 'is_active' => 'on']))->assertForbidden();
});

test('super admin can assign the super-admin role', function () {
    actingAsRole('super-admin');
    $this->post(route('admin.users.store'), validUserPayload(['email' => 'x@empresa.test', 'roles' => ['super-admin']]))->assertRedirect(route('admin.users.index'));
    expect(User::where('email', 'x@empresa.test')->first()->hasRole('super-admin'))->toBeTrue();
});

test('super admin cannot remove own super-admin role', function () {
    $me = actingAsRole('super-admin');
    $this->put(route('admin.users.update', $me), validUserPayload(['name' => $me->name, 'email' => $me->email, 'roles' => ['owner'], 'is_active' => 'on']))->assertForbidden();
    expect($me->fresh()->hasRole('super-admin'))->toBeTrue();
});

test('user cannot deactivate self', function () {
    $me = actingAsRole('owner');
    $this->put(route('admin.users.update', $me), validUserPayload(['name' => $me->name, 'email' => $me->email, 'roles' => ['owner']]))->assertForbidden();
    expect($me->fresh()->is_active)->toBeTrue();
});

test('self edit with the hidden active field keeps the account active', function () {
    $me = actingAsRole('owner');
    $this->put(route('admin.users.update', $me), validUserPayload(['name' => 'Nuevo nombre', 'email' => $me->email, 'roles' => ['owner'], 'is_active' => 'on']))->assertRedirect(route('admin.users.index'));
    expect($me->fresh()->name)->toBe('Nuevo nombre')->and($me->fresh()->is_active)->toBeTrue();
});

test('deactivating requires the deactivate permission', function () {
    $me = actingAsRole('management');
    $me->givePermissionTo('admin.users.update');
    $target = User::factory()->create();
    $target->assignRole('sales');

    $this->put(route('admin.users.update', $target), validUserPayload(['name' => 'Nuevo', 'email' => $target->email]))->assertForbidden();
    $this->put(route('admin.users.update', $target), validUserPayload(['name' => 'Nuevo', 'email' => $target->email, 'is_active' => 'on']))->assertRedirect(route('admin.users.index'));
    expect($target->fresh()->name)->toBe('Nuevo');
});

test('owner updates roles and deactivates a user and it is logged', function () {
    actingAsRole('owner');
    $target = User::factory()->create();
    $target->assignRole('sales');

    $this->put(route('admin.users.update', $target), validUserPayload(['name' => $target->name, 'email' => $target->email, 'roles' => ['accounting']]))
        ->assertRedirect(route('admin.users.index'))->assertSessionHas('success', 'Usuario actualizado');

    expect($target->fresh()->getRoleNames()->all())->toBe(['accounting'])->and($target->fresh()->is_active)->toBeFalse()
        ->and(Activity::inLog('users')->where('event', 'deactivated')->where('subject_id', $target->id)->where('description', "Usuario {$target->name} desactivado")->exists())->toBeTrue()
        ->and(Activity::inLog('access')->where('event', 'role_assigned')->where('subject_id', $target->id)->exists())->toBeTrue();
});

test('edit page includes the record history', function () {
    actingAsRole('owner');
    $target = User::factory()->create();
    $this->get(route('admin.users.edit', $target))->assertOk()->assertInertia(fn ($p) => $p->component('admin/users/form')->has('history')->has('user'));
});

test('a non super admin cannot grant a role with permissions they lack', function () {
    $manager = actingAsRole('management');
    $manager->givePermissionTo(['admin.users.view', 'admin.users.update']);
    $target = User::factory()->create();
    $target->assignRole('sales');

    $this->put(route('admin.users.update', $target), validUserPayload(['name' => $target->name, 'email' => $target->email, 'roles' => ['owner'], 'is_active' => true]))
        ->assertForbidden();
    expect($target->fresh()->hasRole('owner'))->toBeFalse();

    $this->put(route('admin.users.update', $manager), validUserPayload(['name' => $manager->name, 'email' => $manager->email, 'roles' => ['management', 'owner'], 'is_active' => true]))
        ->assertForbidden();
    expect($manager->fresh()->hasRole('owner'))->toBeFalse();
});

test('owner can still assign operational roles', function () {
    actingAsRole('owner');
    $target = User::factory()->create();

    $this->put(route('admin.users.update', $target), validUserPayload(['name' => $target->name, 'email' => $target->email, 'roles' => ['sales', 'warehouse']]))
        ->assertRedirect(route('admin.users.index'));
    expect($target->fresh()->getRoleNames()->sort()->values()->all())->toBe(['sales', 'warehouse']);
});

test('the form only offers roles the actor can assign', function () {
    $manager = actingAsRole('management');
    $manager->givePermissionTo(['admin.users.view', 'admin.users.create']);

    $this->get(route('admin.users.create'))->assertOk()->assertInertia(fn ($p) => $p
        ->where('roleOptions', fn ($options) => collect($options)->pluck('name')->doesntContain('owner') && collect($options)->pluck('name')->doesntContain('super-admin')));
});
