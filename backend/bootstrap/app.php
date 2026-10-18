<?php

use App\Http\Middleware\EnsureMember;
use App\Http\Middleware\ForceHttps;
use App\Http\Middleware\RedirectIfNotInstalled;
use App\Http\Middleware\RefreshSessionCookie;
use App\Http\Middleware\RequireAjaxHeader;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::group([], base_path('routes/install.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Runs after TrustProxies (config/trustedproxy.php), so HTTPS terminated
        // by the hoster's proxy is recognised, and before any route logic.
        $middleware->append(ForceHttps::class);
        $middleware->append(SecurityHeaders::class);
        $middleware->append(SetLocale::class);
        // Global, so it runs before session/cookie handling, which needs an app key.
        $middleware->append(RedirectIfNotInstalled::class);

        $middleware->api(append: [RequireAjaxHeader::class, RefreshSessionCookie::class]);
        // API only: unauthenticated requests get 401 JSON, never a redirect.
        $middleware->redirectGuestsTo(fn () => null);

        // The department must be known before route model binding loads
        // e.g. {team}, so the tenant scope applies to those queries.
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenant::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureMember::class);

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'member' => EnsureMember::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
