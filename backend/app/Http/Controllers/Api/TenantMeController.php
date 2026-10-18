<?php

namespace App\Http\Controllers\Api;

use App\Enums\PersonRelation;
use App\Http\Controllers\Controller;
use App\Models\Person;
use App\Models\PersonAccount;
use App\Models\TeamMembership;
use App\Support\Auth\CurrentMember;
use App\Support\Auth\Permissions;
use Illuminate\Http\JsonResponse;

/**
 * The logged-in member in this department: role, own profile, children,
 * team roles. Drives what the app shows.
 */
class TenantMeController extends Controller
{
    public function __invoke(CurrentMember $current, Permissions $permissions): JsonResponse
    {
        $member = $current->get();
        $links = $permissions->links($member);
        $persons = Person::query()->whereIn('id', $links->pluck('person_id'))->get()->keyBy('id');

        $memberships = TeamMembership::query()
            ->with('team')
            ->whereIn('person_id', $persons->keys())
            ->get();

        return response()->json(['data' => [
            'member_id' => $member->id,
            'is_admin' => $member->is_admin,
            'persons' => $links->map(fn (PersonAccount $link) => [
                'id' => $link->person_id,
                'relation' => $link->relation === PersonRelation::Own ? 'self' : 'guardian',
                'first_name' => $persons[$link->person_id]?->first_name,
                'last_name' => $persons[$link->person_id]?->last_name,
                'teams' => $memberships->where('person_id', $link->person_id)
                    ->filter(fn (TeamMembership $m) => $m->team->archived_at === null)
                    ->map(fn (TeamMembership $m) => ['id' => $m->team_id, 'name' => $m->team->name, 'role' => $m->role->value])
                    ->values()->all(),
            ])->values()->all(),
        ]]);
    }
}
