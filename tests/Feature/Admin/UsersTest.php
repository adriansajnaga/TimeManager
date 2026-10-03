<?php

use App\Enums\Language;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

test('admin creates an employee account with hashed password and verified e-mail', function () {
    Livewire::test('pages::admin.users.form')
        ->set('form.name', 'Jan Kowalski')
        ->set('form.email', 'jan@example.com')
        ->set('form.role', Role::Employee->value)
        ->set('form.locale', Language::German->value)
        ->set('form.personnel_no', '42')
        ->set('form.password', 'secret-password')
        ->set('form.password_confirmation', 'secret-password')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $user = User::query()->where('email', 'jan@example.com')->sole();

    expect($user->role)->toBe(Role::Employee)
        ->and($user->locale)->toBe(Language::German)
        ->and($user->personnel_no)->toBe('42')
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and(Hash::check('secret-password', $user->password))->toBeTrue();
});

test('password is required for a new account', function () {
    Livewire::test('pages::admin.users.form')
        ->set('form.name', 'Bez hasła')
        ->set('form.email', 'nopass@example.com')
        ->call('save')
        ->assertHasErrors(['form.password']);
});

test('empty password on edit keeps the current one', function () {
    $user = User::factory()->create();
    $hash = $user->password;

    Livewire::test('pages::admin.users.form', ['user' => $user])
        ->set('form.name', 'Nowe Nazwisko')
        ->call('save')
        ->assertHasNoErrors();

    expect($user->fresh()->name)->toBe('Nowe Nazwisko')
        ->and($user->fresh()->password)->toBe($hash);
});

test('admin cannot deactivate or demote their own account', function () {
    Livewire::test('pages::admin.users.form', ['user' => $this->admin])
        ->set('form.is_active', false)
        ->set('form.role', Role::Employee->value)
        ->call('save')
        ->assertHasErrors(['form.is_active', 'form.role']);

    expect($this->admin->fresh()->is_active)->toBeTrue()
        ->and($this->admin->fresh()->role)->toBe(Role::Admin);
});

test('admin can deactivate another user', function () {
    $user = User::factory()->create();

    Livewire::test('pages::admin.users.form', ['user' => $user])
        ->set('form.is_active', false)
        ->call('save')
        ->assertHasNoErrors();

    expect($user->fresh()->is_active)->toBeFalse();
});

test('password is not written to the activity log', function () {
    $user = User::factory()->create();

    Livewire::test('pages::admin.users.form', ['user' => $user])
        ->set('form.password', 'another-password')
        ->set('form.password_confirmation', 'another-password')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('activity_log', ['subject_id' => $user->id, 'event' => 'updated']);
});
