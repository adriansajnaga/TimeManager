<?php

use App\Http\Controllers\ContractorLogoController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\MailboxController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'))->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');

    Route::middleware('can:log-own-time')->group(function () {
        Route::livewire('time', 'pages::time.week')->name('time.week');
        Route::livewire('weeks', 'pages::weeks.index')->name('weeks.index');
        Route::livewire('weeks/{workWeek}', 'pages::weeks.show')->name('weeks.show');

        Route::get('weeks/{workWeek}/montageauftrag/{project}', [DocumentController::class, 'montageauftrag'])->name('documents.montageauftrag');
        Route::get('weeks/{workWeek}/montageauftraege', [DocumentController::class, 'montageauftraege'])->name('documents.montageauftraege');
        Route::get('weeks/{workWeek}/stundennachweis/{user}/{contractor}', [DocumentController::class, 'stundennachweis'])->name('documents.stundennachweis');
    });

    Route::middleware('can:manage-settlements')->group(function () {
        Route::get('documents/stundenzettel', [DocumentController::class, 'stundenzettel'])->name('documents.stundenzettel');
        Route::get('documents/mileage', [DocumentController::class, 'mileage'])->name('documents.mileage');
        Route::livewire('settlements', 'pages::settlements.index')->name('settlements.index');
    });

    Route::middleware('can:manage-contractors')->group(function () {
        Route::livewire('contractors', 'pages::contractors.index')->name('contractors.index');
        Route::livewire('contractors/create', 'pages::contractors.form')->name('contractors.create');
        Route::livewire('contractors/{contractor}/edit', 'pages::contractors.form')->name('contractors.edit');
        Route::get('contractors/{contractor}/logo', ContractorLogoController::class)->name('contractors.logo');
    });

    Route::middleware('can:manage-projects')->group(function () {
        Route::livewire('projects', 'pages::projects.index')->name('projects.index');
        Route::livewire('projects/create', 'pages::projects.form')->name('projects.create');
        Route::livewire('projects/{project}/edit', 'pages::projects.form')->name('projects.edit');
        Route::livewire('projects/{project}', 'pages::projects.show')->name('projects.show');
    });

    Route::middleware('can:use-mailbox')->group(function () {
        Route::livewire('mailbox', 'pages::mailbox.index')->name('mailbox.index');
        Route::livewire('mailbox/message', 'pages::mailbox.show')->name('mailbox.show');
        Route::get('mailbox/attachment', [MailboxController::class, 'attachment'])->name('mailbox.attachment');
    });

    Route::middleware('can:manage-invoices')->group(function () {
        Route::livewire('invoices', 'pages::invoices.index')->name('invoices.index');
        Route::livewire('invoices/create', 'pages::invoices.form')->name('invoices.create');
        Route::livewire('invoices/{invoice}', 'pages::invoices.show')->name('invoices.show');
        Route::livewire('invoices/{invoice}/edit', 'pages::invoices.form')->name('invoices.edit');
        Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
        Route::get('invoices/{invoice}/xml', [InvoiceController::class, 'xml'])->name('invoices.xml');
        Route::get('invoices/{invoice}/package', [InvoiceController::class, 'package'])->name('invoices.package');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
