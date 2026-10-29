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
