<?php

namespace App\Http\Controllers\Api;

use App\Enums\Gender;
use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Support\Platform\ClubRegistration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Many clubs, one installation: public registration of a club's volleyball
 * department, and the platform admins' list to approve, suspend or reject
 * clubs. Platform admins see the clubs, never their contents.
 */
class PlatformController extends Controller
{
    public function __construct(private ClubRegistration $clubs) {}

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'club_name' => ['required', 'string', 'min:3', 'max:120'],
            'slug' => ['required', 'string', 'min:2', 'max:64', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::notIn(Tenant::RESERVED_SLUGS), Rule::unique('tenants', 'slug')],
            'city' => ['nullable', 'string', 'max:80'],
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'gender' => ['required', Rule::enum(Gender::class)],
            'email' => ['required', 'email', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            'accept' => ['accepted'],
            // Honeypot: real people never fill this hidden field.
            'website' => ['prohibited'],
        ], ['slug.unique' => __('platform.slug_taken'), 'slug.not_in' => __('platform.slug_taken')]);

        $this->clubs->register($data);

        return response()->json(['message' => __('platform.registered')], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizePlatform($request);

        $members = TenantMember::withoutGlobalScopes()->where('status', 'active')->selectRaw('tenant_id, count(*) as n')->groupBy('tenant_id')->pluck('n', 'tenant_id');
        $teams = Team::withoutGlobalScopes()->whereNull('archived_at')->selectRaw('tenant_id, count(*) as n')->groupBy('tenant_id')->pluck('n', 'tenant_id');

        $clubs = Tenant::query()->orderByRaw("case status when 'pending' then 0 when 'active' then 1 else 2 end")->orderBy('name')->get()
            ->map(fn (Tenant $t) => [
                'id' => $t->id,
                'slug' => $t->slug,
                'name' => $t->name,
                'city' => $t->city,
                'status' => $t->status->value,
                'contact_name' => $t->contact_name,
                'contact_email' => $t->contact_email,
                'note' => $t->registration_note,
                'created_at' => $t->created_at?->toIso8601String(),
                'approved_at' => $t->approved_at?->toIso8601String(),
                'members' => (int) ($members[$t->id] ?? 0),
                'teams' => (int) ($teams[$t->id] ?? 0),
            ])->values();

        return response()->json(['data' => $clubs->all()]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $this->authorizePlatform($request);
        $tenant = Tenant::query()->findOrFail($id);
        abort_unless($tenant->status === TenantStatus::Pending, 422, __('platform.not_pending'));

        $this->clubs->approve($tenant, $request);

        return $this->index($request);
    }

    public function suspend(Request $request, string $id): JsonResponse
    {
        $this->authorizePlatform($request);
        $tenant = Tenant::query()->findOrFail($id);
        abort_unless($tenant->status === TenantStatus::Active, 422);
        $tenant->forceFill(['status' => TenantStatus::Suspended])->save();

        return $this->index($request);
    }

    public function reactivate(Request $request, string $id): JsonResponse
    {
        $this->authorizePlatform($request);
        $tenant = Tenant::query()->findOrFail($id);
        abort_unless($tenant->status === TenantStatus::Suspended, 422);
        $tenant->forceFill(['status' => TenantStatus::Active])->save();

        return $this->index($request);
    }

    /** Only registrations that were never approved can be removed. */
    public function reject(Request $request, string $id): JsonResponse
    {
        $this->authorizePlatform($request);
        $tenant = Tenant::query()->findOrFail($id);
        abort_unless($tenant->status === TenantStatus::Pending, 422, __('platform.not_pending'));

        $this->clubs->reject($tenant);

        return $this->index($request);
    }

    /** Deletes a suspended club with all its data; the slug must be typed to confirm. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->authorizePlatform($request);
        $tenant = Tenant::query()->findOrFail($id);
        abort_unless($tenant->status === TenantStatus::Suspended, 422, __('platform.suspend_first'));
        $request->validate(['confirm' => ['required', 'string', Rule::in([$tenant->slug])]], ['confirm.in' => __('platform.confirm_slug')]);

        $this->clubs->destroy($tenant);

        return $this->index($request);
    }

    private function authorizePlatform(Request $request): void
    {
        abort_unless((bool) $request->user()?->isPlatformAdmin(), 403);
    }
}
