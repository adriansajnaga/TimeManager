<?php

use App\Http\Controllers\CompanyLogoController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin.')->group(function () {
    Route::middleware('can:manage-users')->group(function () {
        Route::livewire('users', 'pages::admin.users.index')->name('users.index');
        Route::livewire('users/create', 'pages::admin.users.form')->name('users.create');
        Route::livewire('users/{user}/edit', 'pages::admin.users.form')->name('users.edit');
    });

    Route::middleware('can:manage-settings')->group(function () {
        Route::livewire('company', 'pages::admin.company')->name('company');
        Route::get('company/logo', CompanyLogoController::class)->name('company.logo');
        Route::livewire('bank-accounts', 'pages::admin.bank-accounts')->name('bank-accounts');
        Route::livewire('vehicles', 'pages::admin.vehicles')->name('vehicles');
        Route::livewire('legacy-import', 'pages::admin.legacy-import')->name('legacy-import');
        Route::livewire('ai', 'pages::admin.ai')->name('ai');
    });
});
