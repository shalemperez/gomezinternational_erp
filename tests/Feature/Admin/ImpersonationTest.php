<?php

use App\Models\User;
use Spatie\Activitylog\Models\Activity;

test('super admin can impersonate an active user and return', function () {
    $admin = actingAsRole('super-admin');
    $target = User::factory()->create();
    $target->assignRole('sales');

    $this->post(route('admin.users.impersonate', $target))->assertRedirect(route('dashboard'))->assertSessionHas('impersonator_id', $admin->id);
    $this->assertAuthenticatedAs($target);
    expect(Activity::inLog('auth')->where('event', 'impersonation_started')->where('causer_id', $admin->id)->where('subject_id', $target->id)->exists())->toBeTrue();

    $this->get(route('dashboard'))->assertInertia(fn ($p) => $p->where('auth.impersonator.id', $admin->id));

    $this->delete(route('admin.impersonate.stop'))->assertRedirect(route('admin.users.index'))->assertSessionMissing('impersonator_id');
    $this->assertAuthenticatedAs($admin);
    expect(Activity::inLog('auth')->where('event', 'impersonation_stopped')->where('causer_id', $admin->id)->exists())->toBeTrue();
});

test('owner cannot impersonate', function () {
    actingAsRole('owner');
    $this->post(route('admin.users.impersonate', User::factory()->create()))->assertForbidden();
});

test('cannot impersonate self, an inactive user or another super admin', function () {
    $admin = actingAsRole('super-admin');
    $other = User::factory()->create();
    $other->assignRole('super-admin');

    $this->post(route('admin.users.impersonate', $admin))->assertForbidden();
    $this->post(route('admin.users.impersonate', User::factory()->inactive()->create()))->assertForbidden();
    $this->post(route('admin.users.impersonate', $other))->assertForbidden();
});

test('stop without an active impersonation redirects to dashboard', function () {
    actingAsRole('owner');
    $this->delete(route('admin.impersonate.stop'))->assertRedirect(route('dashboard'));
});

test('cannot start a nested impersonation', function () {
    $admin = actingAsRole('super-admin');
    $target = User::factory()->create();
    $other = User::factory()->create();

    $this->post(route('admin.users.impersonate', $target))->assertRedirect(route('dashboard'));
    $target->givePermissionTo('admin.users.impersonate');
    $this->post(route('admin.users.impersonate', $other))->assertForbidden();
    expect(session('impersonator_id'))->toBe($admin->id);
});

test('stopping impersonation logs out when the original account was deactivated', function () {
    $admin = actingAsRole('super-admin');
    $target = User::factory()->create();
    $this->post(route('admin.users.impersonate', $target));
    $admin->forceFill(['is_active' => false])->save();

    $this->delete(route('admin.impersonate.stop'))->assertRedirect(route('login'))
        ->assertSessionHas('error', 'Tu cuenta está desactivada. Contacta a un administrador.');
    $this->assertGuest();
});

test('activity recorded while impersonating carries the impersonator id', function () {
    $admin = actingAsRole('super-admin');
    $target = User::factory()->create();
    $this->post(route('admin.users.impersonate', $target));

    $this->get(route('dashboard'))->assertOk();
    $target->forceFill(['name' => 'Cambio durante suplantación'])->save();

    $row = Activity::query()->where('subject_id', $target->id)->where('event', 'updated')->latest('id')->firstOrFail();
    expect($row->impersonator_id)->toBe($admin->id);
});
