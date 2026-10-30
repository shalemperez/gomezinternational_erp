<?php

use App\Models\User;

test('inactive user is logged out on next request', function () {
    $user = User::factory()->inactive()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertRedirect(route('login'))
        ->assertSessionHas('error', 'Tu cuenta está desactivada. Contacta a un administrador.');
    $this->assertGuest();
});

test('active user reaches the dashboard', function () {
    $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk();
});
