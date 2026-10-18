<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CSRF protection for the cookie-authenticated API: changing requests must
 * carry X-Requested-With, which other websites cannot add without a CORS
 * preflight (and CORS is not enabled). SameSite=Lax cookies add a second
 * layer.
 */
class RequireAjaxHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe() && $request->header('X-Requested-With') !== 'XMLHttpRequest') {
            abort(419, 'Missing X-Requested-With header.');
        }

        return $next($request);
    }
}
