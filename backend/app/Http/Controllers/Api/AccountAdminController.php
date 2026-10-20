<?php

namespace App\Http\Controllers\Api;

use App\Enums\MemberStatus;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\TenantMember;
use App\Support\Privacy\AccountDeletion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Superadmins (SUPERADMIN_EMAILS): every account of the platform with its
 * clubs and last use; delete one if needed (like the person themselves
 * would). Inactive accounts are cleaned up by the cron.
 */
class AccountAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeSuperadmin($request);
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'filter' => ['nullable', 'in:all,inactive,warned,marketing'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $inactiveSince = now()->subDays((int) config('platform.inactive_after_days'));

        $page = Account::query()
            ->where('email', 'not like', '%@invalid')
            ->when($data['search'] ?? null, fn ($q, $search) => $q->where('email', 'like', '%'.mb_strtolower($search).'%'))
            ->when(($data['filter'] ?? 'all') === 'inactive', fn ($q) => $q->where('last_active_at', '<', $inactiveSince))
            ->when(($data['filter'] ?? 'all') === 'warned', fn ($q) => $q->whereNotNull('deletion_warned_at'))
            ->when(($data['filter'] ?? 'all') === 'marketing', fn ($q) => $q->whereNotNull('marketing_opt_in_at'))
            ->orderBy('email')
            ->paginate(50);

        $members = TenantMember::withoutGlobalScopes()
            ->join('tenants', 'tenants.id', '=', 'tenant_members.tenant_id')
            ->whereIn('tenant_members.account_id', collect($page->items())->pluck('id'))
            ->get(['tenant_members.account_id', 'tenant_members.is_admin', 'tenant_members.status', 'tenants.name', 'tenants.slug', 'tenants.status as tenant_status'])
            ->groupBy('account_id');

        return response()->json([
            'data' => collect($page->items())->map(fn (Account $a) => [
                'id' => $a->id,
                'email' => $a->email,
                'created_at' => $a->created_at?->toIso8601ZuluString(),
                'last_active_at' => $a->last_active_at?->toIso8601ZuluString(),
                'deletion_warned_at' => $a->deletion_warned_at?->toIso8601ZuluString(),
                'deletion_due_at' => $a->deletion_warned_at?->copy()->addDays((int) config('platform.deletion_notice_days'))->toIso8601ZuluString(),
                'marketing_opt_in_at' => $a->marketing_opt_in_at?->toIso8601ZuluString(),
                'is_platform_admin' => (bool) $a->is_platform_admin,
                'is_superadmin' => $a->isSuperadmin(),
                'clubs' => ($members[$a->id] ?? collect())->map(fn ($m) => [
                    'name' => $m->name,
                    'slug' => $m->slug,
                    'is_admin' => (bool) $m->is_admin,
                    'active' => $m->status === MemberStatus::Active->value || $m->status === MemberStatus::Active,
                ])->values()->all(),
            ])->all(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    /** The e-mail address has to be typed again; never the own account. */
    public function destroy(Request $request, string $id, AccountDeletion $deletion): JsonResponse
    {
        $this->authorizeSuperadmin($request);
        $account = Account::query()->findOrFail($id);
        abort_if($account->id === $request->user()->id || $account->isDeleted(), 422, __('account.not_this_one'));
        $data = $request->validate(['confirm' => ['required', 'string']]);
        if (mb_strtolower(trim($data['confirm'])) !== $account->email) {
            return response()->json(['message' => __('account.confirm_email'), 'errors' => ['confirm' => [__('account.confirm_email')]]], 422);
        }

        $deletion->delete($account);

        return response()->json(['message' => __('account.deleted_by_admin')]);
    }

    private function authorizeSuperadmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->isSuperadmin(), 403);
    }
}
