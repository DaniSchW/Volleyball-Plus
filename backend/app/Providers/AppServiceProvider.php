<?php

namespace App\Providers;

use App\Support\Auth\CurrentMember;
use App\Support\Auth\Permissions;
use App\Support\Auth\SessionManager;
use App\Support\Install\Installation;
use App\Support\Mail\PhpMailTransport;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(CurrentMember::class);
        $this->app->scoped(Permissions::class);
        $this->app->singleton(SessionManager::class);
    }

    public function boot(): void
    {
        TenantSchema::register();

        Mail::extend('phpmail', fn () => new PhpMailTransport);

        // Login state comes from the magic-link session cookie, not from PHP sessions.
        Auth::viaRequest('vp-session', function (Request $request) {
            if (! Installation::isInstalled()) {
                return null;
            }

            return app(SessionManager::class)->resolve($request)?->account;
        });
    }
}
