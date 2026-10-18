<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the UI language for server-generated texts. Until accounts exist
 * this follows the browser; later the language chosen in the profile wins.
 */
class SetLocale
{
    /** Languages with translation files in lang/. */
    public const SUPPORTED = ['de', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($request->getPreferredLanguage(self::SUPPORTED) ?? config('app.locale'));

        return $next($request);
    }
}
