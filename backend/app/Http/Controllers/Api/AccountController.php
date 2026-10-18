<?php

namespace App\Http\Controllers\Api;

use App\Enums\MemberStatus;
use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use App\Models\AccountSession;
use App\Models\TenantMember;
use App\Support\Auth\SessionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The logged-in account across departments: language, departments, devices.
 */
class AccountController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $account = $request->user();

        // Departments the account belongs to (bypasses the tenant scope on purpose).
        $tenants = TenantMember::withoutGlobalScopes()
            ->join('tenants', 'tenants.id', '=', 'tenant_members.tenant_id')
            ->where('tenant_members.account_id', $account->id)
            ->where('tenant_members.status', MemberStatus::Active)
            ->where('tenants.status', 'active')
            ->orderBy('tenants.name')
            ->get(['tenants.slug', 'tenants.name']);

        return response()->json(['data' => [
            'email' => $account->email,
            'locale' => $account->locale,
            'tenants' => $tenants->map(fn ($t) => ['slug' => $t->slug, 'name' => $t->name])->all(),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['locale' => ['required', Rule::in(SetLocale::SUPPORTED)]]);

        $request->user()->forceFill(['locale' => $data['locale']])->save();

        return $this->show($request);
    }

    public function sessions(Request $request, SessionManager $manager): JsonResponse
    {
        $current = $manager->current($request);

        $sessions = $request->user()->sessions()
            ->whereNull('revoked_at')
            ->where('expires_at', '>', Carbon::now())
            ->orderByDesc('last_seen_at')
            ->get();

        return response()->json(['data' => $sessions->map(fn (AccountSession $s) => [
            'id' => $s->id,
            'user_agent' => $s->user_agent,
            'last_seen_at' => $s->last_seen_at->toIso8601String(),
            'created_at' => $s->created_at->toIso8601String(),
            'current' => $s->id === $current?->id,
        ])->all()]);
    }

    public function revokeSession(Request $request, string $session, SessionManager $manager): JsonResponse
    {
        $target = $request->user()->sessions()->whereKey($session)->firstOrFail();
        $manager->revoke($target);

        return response()->json(['message' => 'ok']);
    }
}
