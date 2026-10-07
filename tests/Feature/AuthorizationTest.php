<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\User;

dataset('admin pages', [
    'contractors' => fn () => route('contractors.index'),
    'new contractor' => fn () => route('contractors.create'),
    'edit contractor' => fn () => route('contractors.edit', Contractor::factory()->create()),
    'projects' => fn () => route('projects.index'),
    'new project' => fn () => route('projects.create'),
    'edit project' => fn () => route('projects.edit', Project::factory()->create()),
    'users' => fn () => route('admin.users.index'),
    'new user' => fn () => route('admin.users.create'),
    'company' => fn () => route('admin.company'),
    'bank accounts' => fn () => route('admin.bank-accounts'),
    'vehicles' => fn () => route('admin.vehicles'),
]);

test('admin can open every administration page', function (string $url) {
    $this->actingAs(User::factory()->admin()->create())->get($url)->assertOk();
})->with('admin pages');

test('employee is forbidden from administration pages', function (string $url) {
    $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
})->with('admin pages');

test('guests are redirected to login', function (string $url) {
    $this->get($url)->assertRedirect(route('login'));
})->with('admin pages');

test('roles map to permissions', function () {
    // Administrator: wszystko poza widokiem klienta; klient: tylko swój widok.
    expect(Role::Admin->allows(Permission::ManageSettings))->toBeTrue()
        ->and(Role::Admin->allows(Permission::ViewClientPortal))->toBeFalse()
        ->and(count(Role::Admin->permissions()))->toBe(count(Permission::cases()) - 1)
        ->and(Role::Client->permissions())->toBe([Permission::ViewClientPortal])
        ->and(Role::Employee->allows(Permission::LogOwnTime))->toBeTrue()
        ->and(Role::Employee->allows(Permission::ManageInvoices))->toBeFalse()
        ->and(Role::Employee->allows(Permission::ManageSettings))->toBeFalse();
});

test('inactive admin loses all permissions', function () {
    $admin = User::factory()->admin()->inactive()->create();

    expect($admin->hasPermission(Permission::ManageSettings))->toBeFalse();
});

test('sidebar shows administration only to admins', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('dashboard'))
        ->assertSee(route('contractors.index'))
        ->assertSee(route('admin.users.index'));

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertDontSee(route('contractors.index'))
        ->assertDontSee(route('admin.users.index'));
});
