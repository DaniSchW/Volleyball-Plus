<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CrossNomination;
use App\Models\Person;
use App\Models\Team;
use App\Models\TenantMember;
use App\Support\Auth\CurrentMember;
use App\Support\Auth\Permissions;
use App\Support\Statistics\AttendanceStatistics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Reply rates per team. Trainers and admins see everybody; players (and
 * parents for their children) only their own rate; organizers none – so
 * no competition arises among helpers.
 */
class StatisticsController extends Controller
{
    public function __construct(private CurrentMember $current, private Permissions $permissions, private AttendanceStatistics $statistics) {}

    public function team(Request $request, Team $team): JsonResponse
    {
        $member = $this->current->get();
        abort_unless($this->mayOpen($member, $team), 403);
        [$from, $to, $types] = $this->period($request);

        $all = $this->canSeeAll($member, $team);
        $events = $this->statistics->events($team, $from, $to, $types);

        return response()->json(['data' => [
            'period' => ['from' => $from, 'to' => $to],
            'presets' => $this->statistics->presets(),
            'types' => $types,
            'can_see_all' => $all,
            'event_count' => $events->count(),
            'rows' => $this->statistics->rows($team, $events, $all ? null : $this->permissions->actingPersonIds($member)),
        ]]);
    }

    /** Who took how many tasks / helper services – same visibility as the rates. */
    public function tasks(Request $request, Team $team): JsonResponse
    {
        $member = $this->current->get();
        abort_unless($this->mayOpen($member, $team), 403);
        [$from, $to] = $this->period($request);
        $all = $this->canSeeAll($member, $team);

        return response()->json(['data' => [
            'can_see_all' => $all,
            'rows' => $this->statistics->taskRows($team, $from, $to, $all ? null : $this->permissions->actingPersonIds($member)),
        ]]);
    }

    /** Event by event for one person: trainers/admins, or the person (parents). */
    public function person(Request $request, Team $team, Person $person): JsonResponse
    {
        $member = $this->current->get();
        abort_unless($this->mayOpen($member, $team), 403);
        abort_unless($this->canSeeAll($member, $team) || $this->permissions->actsFor($member, $person), 403);
        [$from, $to, $types] = $this->period($request);

        return response()->json(['data' => $this->statistics->history($this->statistics->events($team, $from, $to, $types), $person->id)]);
    }

    private function canSeeAll(TenantMember $member, Team $team): bool
    {
        return $this->permissions->isAdmin($member) || $this->permissions->isTrainerOf($member, $team);
    }

    /** Team members and the team's cross-nominated guests (for their own rate). */
    private function mayOpen(TenantMember $member, Team $team): bool
    {
        return $this->permissions->canSeeTeam($member, $team)
            || CrossNomination::query()->where('host_team_id', $team->id)->whereIn('person_id', $this->permissions->actingPersonIds($member))->exists();
    }

    /**
     * @return array{0: string, 1: string, 2: list<string>}
     */
    private function period(Request $request): array
    {
        $data = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'types' => ['sometimes', 'array'],
            'types.*' => [Rule::in(AttendanceStatistics::TYPES)],
        ]);
        $season = $this->statistics->presets()['season'];

        return [$data['from'] ?? $season['from'], $data['to'] ?? $season['to'], array_values($data['types'] ?? AttendanceStatistics::TYPES)];
    }
}
