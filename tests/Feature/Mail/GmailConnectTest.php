<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Spatie\Activitylog\Models\Activity;

test('connect flow stores the refresh token encrypted and disconnect clears it', function () {
    $user = User::factory()->create(['email' => 'ana@empresa.test']);
    $this->actingAs($user);
    Socialite::fake('google');
    $this->get(route('auth.gmail.connect'))->assertRedirect();

    Socialite::fake('google', GoogleUser::fake(['email' => 'ana@empresa.test', 'refreshToken' => 'rt-123', 'approvedScopes' => ['https://www.googleapis.com/auth/gmail.send']]));
    $this->get(route('auth.gmail.callback'))->assertRedirect(route('dashboard'))->assertSessionHas('success', 'Gmail conectado: ana@empresa.test');
    $user->refresh();
    expect($user->gmail_refresh_token)->toBe('rt-123')->and($user->gmail_email)->toBe('ana@empresa.test')->and($user->gmail_connected_at)->not->toBeNull()
        ->and(DB::table('users')->where('id', $user->id)->value('gmail_refresh_token'))->not->toBe('rt-123')
        ->and(Activity::inLog('auth')->where('event', 'gmail_connected')->where('causer_id', $user->id)->exists())->toBeTrue()
        ->and(Activity::inLog('users')->where('subject_id', $user->id)->get()->every(fn ($a) => ! str_contains(json_encode($a->properties), 'rt-123')))->toBeTrue();

    $this->delete(route('auth.gmail.disconnect'))->assertRedirect()->assertSessionHas('success', 'Gmail desconectado');
    expect($user->fresh()->gmail_refresh_token)->toBeNull()->and($user->fresh()->gmail_connected_at)->toBeNull();
});

test('callback refuses a google account different from the user and a missing send scope', function () {
    $user = User::factory()->create(['email' => 'ana@empresa.test']);
    $this->actingAs($user);
    Socialite::fake('google', GoogleUser::fake(['email' => 'otra@empresa.test', 'refreshToken' => 'rt', 'approvedScopes' => ['https://www.googleapis.com/auth/gmail.send']]));
    $this->get(route('auth.gmail.callback'))->assertRedirect(route('dashboard'))->assertSessionHas('error', 'Debes conectar la misma cuenta de Google con la que iniciaste sesión (ana@empresa.test).');
    Socialite::fake('google', GoogleUser::fake(['email' => 'ana@empresa.test', 'refreshToken' => 'rt', 'approvedScopes' => []]));
    $this->get(route('auth.gmail.callback'))->assertRedirect(route('dashboard'))->assertSessionHas('error', 'Google no concedió el permiso para enviar correos (gmail.send).');
});
