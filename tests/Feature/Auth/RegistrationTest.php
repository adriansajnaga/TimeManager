<?php

use App\Enums\Role;
use App\Models\User;

test('registration is open while there are no users', function () {
    $this->get(route('register'))->assertOk();

    $this->get(route('login'))->assertSee('Create the administrator account');
});

test('the first registered user becomes a verified administrator', function () {
    $this->post(route('register.store'), [
        'name' => 'Adrian Sajnaga',
        'email' => 'adrian@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasNoErrors()->assertRedirect(route('dashboard', absolute: false));

    $user = User::sole();

    expect($user->role)->toBe(Role::Admin)
        ->and($user->hasVerifiedEmail())->toBeTrue();

    $this->assertAuthenticatedAs($user);
});

test('registration is closed once any account exists', function () {
    User::factory()->admin()->create();

    $this->get(route('register'))->assertNotFound();

    $this->post(route('register.store'), [
        'name' => 'Intruder',
        'email' => 'intruder@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertForbidden();

    expect(User::count())->toBe(1);

    $this->get(route('login'))->assertDontSee('Create the administrator account');
});
