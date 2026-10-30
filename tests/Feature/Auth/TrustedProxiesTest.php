<?php

use App\Models\User;
use App\Providers\AppServiceProvider;
use Spatie\Activitylog\Models\Activity;

test('audit rows record the client ip behind a trusted proxy', function () {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->withServerVariables(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'])
        ->post(route('logout'))->assertRedirect(route('login'));

    $row = Activity::inLog('auth')->where('event', 'logout')->where('causer_id', $user->id)->latest('id')->firstOrFail();
    expect($row->properties['ip'])->toBe('203.0.113.9')->and($row->ip)->toBe('203.0.113.9');
});

test('forwarded headers are ignored when no proxy is configured', function () {
    config(['erp.trusted_proxies' => '']);
    AppServiceProvider::configureTrustedProxies();
    $user = User::factory()->create();
    $this->actingAs($user)
        ->withServerVariables(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'])
        ->post(route('logout'))->assertRedirect(route('login'));

    $row = Activity::inLog('auth')->where('event', 'logout')->where('causer_id', $user->id)->latest('id')->firstOrFail();
    expect($row->ip)->toBe('10.0.0.5');
});

test('trusted proxies come from config, not from the bootstrap file', function () {
    expect(config('erp.trusted_proxies'))->toBe('*')
        ->and(file_get_contents(base_path('bootstrap/app.php')))->not->toContain('TRUSTED_PROXIES');
});
