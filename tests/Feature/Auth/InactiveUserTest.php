<?php

use App\Models\User;

test('inactive users cannot log in', function () {
    $user = User::factory()->inactive()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('active users can log in', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($user);
});

test('home shows the start page to guests and redirects users to the dashboard', function () {
    $this->get(route('home'))->assertOk()->assertSee('MANAGER')->assertSee(route('login'), false);

    $this->actingAs(User::factory()->create())->get(route('home'))->assertRedirect(route('dashboard'));
});
