<?php

namespace App\Services\Ai;

use App\Enums\Language;

/**
 * Poprawianie i tłumaczenie opisów prac (Montageauftrag).
 */
interface TextAssistant
{
    /**
     * Poprawiony tekst; z $target — przetłumaczony na ten język, bez $target — w języku oryginału.
     *
     * @param  'performed'|'remaining'  $section
     *
     * @throws AiException
     */
    public function rewrite(string $text, string $section, ?Language $target): string;

    /**
     * Krótkie zapytanie sprawdzające klucz i model.
     *
     * @throws AiException
     */
    public function ping(): void;
}
