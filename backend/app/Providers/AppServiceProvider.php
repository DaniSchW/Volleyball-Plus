<?php

namespace App\Providers;

use App\Support\Mail\PhpMailTransport;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantSchema;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
    }

    public function boot(): void
    {
        TenantSchema::register();

        Mail::extend('phpmail', fn () => new PhpMailTransport);
    }
}
