<?php

namespace App\Http\Middleware;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the department from the `{tenant}` route segment (its slug) and
 * activates it for the rest of the request.
 */
class ResolveTenant
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->route('tenant');

        $tenant = is_string($slug)
            ? Tenant::query()->where('slug', $slug)->where('status', TenantStatus::Active)->first()
            : null;

        abort_if($tenant === null, 404, __('app.errors.tenant_not_found'));

        $this->context->set($tenant);
        $request->route()?->forgetParameter('tenant');

        try {
            return $next($request);
        } finally {
            $this->context->forget();
        }
    }
}
