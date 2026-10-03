<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'))->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::middleware('can:manage-contractors')->group(function () {
        Route::livewire('contractors', 'pages::contractors.index')->name('contractors.index');
        Route::livewire('contractors/create', 'pages::contractors.form')->name('contractors.create');
        Route::livewire('contractors/{contractor}/edit', 'pages::contractors.form')->name('contractors.edit');
    });

    Route::middleware('can:manage-projects')->group(function () {
        Route::livewire('projects', 'pages::projects.index')->name('projects.index');
        Route::livewire('projects/create', 'pages::projects.form')->name('projects.create');
        Route::livewire('projects/{project}/edit', 'pages::projects.form')->name('projects.edit');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
