<?php

namespace App\Http\Middleware;

use App\Enums\MemberStatus;
use App\Models\TenantMember;
use App\Support\Auth\CurrentMember;
use App\Support\Auth\Permissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires a logged-in account that is an active member of the current
 * department (after "tenant").
 */
class EnsureMember
{
    public function __construct(private CurrentMember $current, private Permissions $permissions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->user();
        abort_if($account === null, 401, __('auth.unauthenticated'));

        $member = TenantMember::query()
            ->where('account_id', $account->getKey())
            ->where('status', MemberStatus::Active)
            ->first();

        abort_if($member === null || ! $this->permissions->mayUseApp($member), 403, __('auth.not_a_member'));

        $this->current->set($member);

        try {
            return $next($request);
        } finally {
            $this->current->forget();
        }
    }
}
