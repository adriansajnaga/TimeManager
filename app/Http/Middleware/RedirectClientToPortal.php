<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Klient nie ma pulpitu firmy — trafia do swoich projektów.
 */
class RedirectClientToPortal
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isClient()) {
            return redirect()->route('portal.index');
        }

        return $next($request);
    }
}
