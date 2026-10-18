<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * No unencrypted access: plain-HTTP page loads are redirected to HTTPS,
 * everything else (API calls, form posts) is refused outright so no data
 * is ever processed over an insecure connection.
 */
class ForceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('security.force_https') || $request->secure()) {
            return $next($request);
        }

        if ($request->isMethod('GET') && ! $request->is('api/*')) {
            return redirect()->secure($request->getRequestUri(), 301);
        }

        abort(403, __('app.errors.https_required'));
    }
}
