<?php

namespace App\Http\Middleware;

use App\Support\Install\Installation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Until the web installer has run, every page leads to it and the API
 * answers 503.
 */
class RedirectIfNotInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Installation::isInstalled() || $request->is('install', 'up')) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => __('app.errors.not_installed')], 503);
        }

        return redirect('/install');
    }
}
