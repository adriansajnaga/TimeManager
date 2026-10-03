<?php

use App\Enums\Language;
use App\Models\User;
use Livewire\Livewire;

test('interface follows the user language', function (Language $language, string $expected) {
    $user = User::factory()->admin()->create(['locale' => $language]);

    $this->actingAs($user)->get(route('contractors.index'))->assertSee($expected);
})->with([
    'Polish' => [Language::Polish, 'Kontrahenci'],
    'German' => [Language::German, 'Geschäftspartner'],
    'English' => [Language::English, 'Contractors'],
]);

test('user changes the interface language in the profile', function () {
    $user = User::factory()->create(['locale' => Language::English]);

    Livewire::actingAs($user)
        ->test('pages::settings.profile')
        ->set('locale', 'de')
        ->call('updateProfileInformation')
        ->assertHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh()->locale)->toBe(Language::German);
});

test('unsupported language is rejected', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.profile')
        ->set('locale', 'fr')
        ->call('updateProfileInformation')
        ->assertHasErrors(['locale']);
});
