<?php

namespace App\Http\Middleware;

use App\Support\Install\Installation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the language for server-generated texts: the one chosen in the
 * account, otherwise the browser's.
 */
class SetLocale
{
    /** Languages with translation files in lang/. */
    public const SUPPORTED = ['de', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $chosen = Installation::isInstalled() ? $request->user()?->locale : null;

        app()->setLocale(in_array($chosen, self::SUPPORTED, true)
            ? $chosen
            : ($request->getPreferredLanguage(self::SUPPORTED) ?? config('app.locale')));

        return $next($request);
    }
}
