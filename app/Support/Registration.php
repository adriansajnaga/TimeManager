<?php

namespace App\Support;

use App\Models\User;

/**
 * Konta zakłada administrator. Samodzielna rejestracja jest otwarta tylko w pustej aplikacji,
 * żeby na hostingu bez SSH dało się utworzyć pierwsze konto (zostaje administratorem).
 */
class Registration
{
    public static function isOpen(): bool
    {
        return ! User::query()->exists();
    }
}
