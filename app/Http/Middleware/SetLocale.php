<?php

namespace App\Http\Middleware;

use App\Enums\Language;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Język interfejsu: z profilu zalogowanego użytkownika, a dla gości z sesji.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()->locale
            ?? Language::tryFrom((string) $request->session()->get('locale'));

        if ($locale instanceof Language) {
            app()->setLocale($locale->value);
        }

        return $next($request);
    }
}
