<?php

namespace App\Http\Controllers\Api;

use App\Enums\MemberStatus;
use App\Http\Controllers\Controller;
use App\Models\TenantMember;
use App\Support\Auth\CurrentMember;
use App\Support\Auth\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Department members with login (admins only): who has an account, who is admin.
 */
class MemberController extends Controller
{
    public function __construct(private CurrentMember $current, private Permissions $permissions) {}

    public function index(): JsonResponse
    {
        abort_unless($this->permissions->isAdmin($this->current->get()), 403);

        $members = TenantMember::query()->with(['account', 'persons'])->get()
            ->sortBy(fn (TenantMember $m) => $m->account->email);

        return response()->json(['data' => $members->map(fn (TenantMember $m) => [
            'id' => $m->id,
            'email' => $m->account->email,
            'is_admin' => $m->is_admin,
            'status' => $m->status->value,
            'persons' => $m->persons->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->first_name.' '.$p->last_name,
                'relation' => $p->pivot->relation->value,
            ])->values()->all(),
        ])->values()->all()]);
    }

    public function update(Request $request, TenantMember $member): JsonResponse
    {
        abort_unless($this->permissions->isAdmin($this->current->get()), 403);

        $data = $request->validate([
            'is_admin' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'in:active,disabled'],
        ]);

        $losesAdmin = $member->is_admin
            && (($data['is_admin'] ?? true) === false || ($data['status'] ?? 'active') === MemberStatus::Disabled->value);
        $otherAdmins = TenantMember::query()->where('is_admin', true)->where('status', MemberStatus::Active)->whereKeyNot($member->id)->exists();
        abort_if($losesAdmin && ! $otherAdmins, 422, __('persons.last_admin'));

        $member->forceFill($data)->save();

        return response()->json(['data' => ['id' => $member->id, 'is_admin' => $member->is_admin, 'status' => $member->status->value]]);
    }
}
