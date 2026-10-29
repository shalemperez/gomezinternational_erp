<?php

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    (new PermissionSeeder)->run();
    config(['erp.google_workspace_domain' => 'empresa.test', 'erp.super_admin_emails' => ['soporte@externo.test']]);
});

function fakeGoogle(array $overrides = []): void
{
    Socialite::fake('google', GoogleUser::fake(array_merge([
        'id' => 'google-123', 'name' => 'Ana Pérez', 'email' => 'ana@empresa.test',
        'avatar' => 'https://example.test/a.png', 'hd' => 'empresa.test', 'email_verified' => true,
    ], $overrides)));
}

function activityInstalled(): bool
{
    return class_exists(Activity::class);
}

test('login page renders the google button', function () {
    $this->get(route('login'))->assertOk()->assertInertia(fn ($p) => $p->component('auth/login'));
});

test('redirect route sends the user to google', function () {
    Socialite::fake('google');
    $this->get(route('auth.google.redirect'))->assertRedirect();
});

test('invited active user from the workspace domain logs in', function () {
    $user = User::factory()->create(['email' => 'ana@empresa.test']);
    fakeGoogle();

    $this->get(route('auth.google.callback'))->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    $user->refresh();
    expect($user->google_id)->toBe('google-123')
        ->and($user->avatar_url)->toBe('https://example.test/a.png')
        ->and($user->last_login_at)->not->toBeNull()
        ->and(Activity::inLog('auth')->where('event', 'login')->where('causer_id', $user->id)->exists())->toBeTrue();
})->skip(! activityInstalled(), 'activitylog is installed in Task 7');

test('email matching is case insensitive', function () {
    $user = User::factory()->create(['email' => 'ana@empresa.test']);
    fakeGoogle(['email' => 'Ana@EMPRESA.TEST']);

    $this->get(route('auth.google.callback'))->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);
});

test('account outside the workspace domain is rejected', function () {
    User::factory()->create(['email' => 'ana@otro.test']);
    fakeGoogle(['email' => 'ana@otro.test', 'hd' => 'otro.test']);

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('error', 'Solo se permite iniciar sesión con una cuenta de Google Workspace de empresa.test.');
    $this->assertGuest();
    expect(Activity::inLog('auth')->where('event', 'login_rejected')->where('properties->email', 'ana@otro.test')->where('properties->reason', 'domain')->exists())->toBeTrue();
})->skip(! activityInstalled(), 'activitylog is installed in Task 7');

test('account without hd claim is rejected even if the email domain matches', function () {
    User::factory()->create(['email' => 'ana@empresa.test']);
    fakeGoogle(['hd' => null]);

    $this->get(route('auth.google.callback'))->assertRedirect(route('login'))
        ->assertSessionHas('error', 'Solo se permite iniciar sesión con una cuenta de Google Workspace de empresa.test.');
    $this->assertGuest();
    expect(Activity::inLog('auth')->where('event', 'login_rejected')->where('properties->reason', 'hd_missing')->exists())->toBeTrue();
})->skip(! activityInstalled(), 'activitylog is installed in Task 7');

test('not invited account is rejected', function () {
    fakeGoogle();

    $this->get(route('auth.google.callback'))->assertRedirect(route('login'))
        ->assertSessionHas('error', 'Tu cuenta no está registrada en el sistema. Pide a un administrador que te invite.');
    $this->assertGuest();
    expect(User::count())->toBe(0);
});

test('inactive account is rejected', function () {
    User::factory()->inactive()->create(['email' => 'ana@empresa.test']);
    fakeGoogle();

    $this->get(route('auth.google.callback'))->assertRedirect(route('login'))
        ->assertSessionHas('error', 'Tu cuenta está desactivada. Contacta a un administrador.');
    $this->assertGuest();
});

test('unverified google email is rejected', function () {
    User::factory()->create(['email' => 'ana@empresa.test']);
    fakeGoogle(['email_verified' => false]);

    $this->get(route('auth.google.callback'))->assertRedirect(route('login'))
        ->assertSessionHas('error', 'Google no ha verificado el correo de esta cuenta.');
    $this->assertGuest();
});

test('allow-listed support email outside the domain is created as super admin on first login', function () {
    fakeGoogle(['email' => 'soporte@externo.test', 'hd' => null]);

    $this->get(route('auth.google.callback'))->assertRedirect(route('dashboard'));

    $user = User::where('email', 'soporte@externo.test')->firstOrFail();
    $this->assertAuthenticatedAs($user);
    expect($user->hasRole('super-admin'))->toBeTrue();
});

test('logout ends the session and is logged', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->post(route('logout'))->assertRedirect(route('login'));
    $this->assertGuest();
    expect(Activity::inLog('auth')->where('event', 'logout')->where('causer_id', $user->id)->exists())->toBeTrue();
})->skip(! activityInstalled(), 'activitylog is installed in Task 7');

test('login is rejected when the stored google id differs from the google account', function () {
    User::factory()->create(['email' => 'ana@empresa.test', 'google_id' => 'google-OLD']);
    fakeGoogle();

    $this->get(route('auth.google.callback'))->assertRedirect(route('login'))
        ->assertSessionHas('error', 'Esta cuenta de Google no coincide con el usuario registrado. Contacta a un administrador.');
    $this->assertGuest();
    expect(Activity::inLog('auth')->where('event', 'login_rejected')->latest('id')->first()?->getProperty('reason'))->toBe('identity_mismatch');
});

test('first login stores the google id and a later login with the same id succeeds', function () {
    $user = User::factory()->create(['email' => 'ana@empresa.test', 'google_id' => null]);
    fakeGoogle();
    $this->get(route('auth.google.callback'))->assertRedirect(route('dashboard'));
    expect($user->fresh()->google_id)->toBe('google-123');

    auth()->logout();
    fakeGoogle();
    $this->get(route('auth.google.callback'))->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user->fresh());
});

test('workspace domain from config is compared case insensitively', function () {
    config(['erp.google_workspace_domain' => 'Empresa.TEST']);
    User::factory()->create(['email' => 'ana@empresa.test']);
    fakeGoogle();

    $this->get(route('auth.google.callback'))->assertRedirect(route('dashboard'));
});

test('oauth errors are logged without the raw provider message', function () {
    Socialite::shouldReceive('driver->user')->andThrow(new RuntimeException('secret provider body'));

    $this->get(route('auth.google.callback'))->assertRedirect(route('login'));
    $row = Activity::inLog('auth')->where('event', 'login_rejected')->latest('id')->firstOrFail();
    expect($row->getProperty('reason'))->toBe('oauth_error')
        ->and($row->getProperty('exception'))->toBe(RuntimeException::class)
        ->and($row->getProperty('message'))->toBeNull();
});
