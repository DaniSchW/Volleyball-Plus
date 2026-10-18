<?php

namespace App\Http\Controllers\Api;

use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Models\CrossNomination;
use App\Models\Person;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Support\Auth\CurrentMember;
use App\Support\Auth\Permissions;
use App\Support\Auth\PersonPresenter;
use App\Support\Teams\TeamHierarchy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Trainers (and admins) invite players of lower teams to their team's
 * trainings for good. Rules: no youth teams, only from lower to higher
 * teams, Herren teams only men, Damen teams only women, mixed both.
 */
class CrossNominationController extends Controller
{
    public function __construct(
        private CurrentMember $current,
        private Permissions $permissions,
        private TeamHierarchy $hierarchy,
        private PersonPresenter $presenter,
    ) {}

    public function index(Team $team): JsonResponse
    {
        abort_unless($this->permissions->canInviteToTeam($this->current->get(), $team), 403);

        $rows = CrossNomination::query()->where('host_team_id', $team->id)->with(['person', 'homeTeam'])->get()
            ->filter(fn (CrossNomination $n) => $n->person !== null)
            ->sortBy(fn (CrossNomination $n) => mb_strtolower($n->person->last_name.' '.$n->person->first_name))
            ->map(fn (CrossNomination $n) => $this->present($n))->values();

        return response()->json(['data' => $rows->all()]);
    }

    /** Players who could be invited: from lower teams, matching the gender rule. */
    public function candidates(Team $team): JsonResponse
    {
        abort_unless($this->permissions->canManageTeam($this->current->get(), $team), 403);

        $lowerTeams = Team::query()->with('league')->whereNull('archived_at')->get()
            ->filter(fn (Team $other) => $this->hierarchy->isHigher($team, $other, sameKind: false))
            ->keyBy('id');
        $taken = CrossNomination::query()->where('host_team_id', $team->id)->pluck('person_id')
            ->merge(TeamMembership::query()->where('team_id', $team->id)->pluck('person_id'))
            ->flip();

        $memberships = TeamMembership::query()->with('person')
            ->whereIn('team_id', $lowerTeams->keys())->where('role', TeamRole::Player)->get()
            ->filter(fn (TeamMembership $m) => $m->person !== null && ! $taken->has($m->person_id)
                && $this->hierarchy->genderFits($team, $m->person->gender));

        $rows = $memberships->groupBy('person_id')->map(function ($group) use ($lowerTeams) {
            $person = $group->first()->person;
            // Offer the highest of the person's lower teams as home team.
            $home = $group->map(fn (TeamMembership $m) => $lowerTeams[$m->team_id])
                ->sortBy(fn (Team $t) => $this->hierarchy->sortKey($t))->first();

            return [
                'person' => $this->presenter->present($person, $this->current->get()),
                'home_team' => ['id' => $home->id, 'name' => $home->name],
            ];
        })->sortBy(fn ($row) => mb_strtolower($row['home_team']['name'].' '.$row['person']['last_name']))->values();

        return response()->json(['data' => $rows->all()]);
    }

    public function store(Request $request, Team $team): JsonResponse
    {
        $member = $this->current->get();
        abort_unless($this->permissions->canManageTeam($member, $team), 403);
        $data = $request->validate([
            'person_id' => ['required', 'string'],
            'home_team_id' => ['required', 'string'],
        ]);

        $person = Person::query()->findOrFail($data['person_id']);
        $home = Team::query()->whereNull('archived_at')->findOrFail($data['home_team_id']);
        $this->check($team, $home, $person);

        $nomination = CrossNomination::query()->updateOrCreate(
            ['host_team_id' => $team->id, 'person_id' => $person->id],
            ['home_team_id' => $home->id, 'active' => true, 'invited_by' => $member->id],
        );

        return response()->json(['data' => $this->present($nomination->load(['person', 'homeTeam']))], 201);
    }

    /** Switch on/off per player. */
    public function update(Request $request, CrossNomination $nomination): JsonResponse
    {
        abort_unless($this->permissions->canManageTeam($this->current->get(), $nomination->hostTeam), 403);
        $data = $request->validate(['active' => ['required', 'boolean']]);

        $nomination->fill($data)->save();

        return response()->json(['data' => $this->present($nomination->load(['person', 'homeTeam']))]);
    }

    public function destroy(CrossNomination $nomination): JsonResponse
    {
        abort_unless($this->permissions->canManageTeam($this->current->get(), $nomination->hostTeam), 403);

        $nomination->delete();

        return response()->json(['message' => 'ok']);
    }

    private function check(Team $host, Team $home, Person $person): void
    {
        $fail = fn (string $key, array $params = []) => throw ValidationException::withMessages(['person_id' => __("teams.cross.{$key}", $params)]);

        if ($host->is_youth || $home->is_youth) {
            $fail('youth');
        }
        if (! $this->hierarchy->isHigher($host, $home, sameKind: false)) {
            $fail('only_upwards', ['host' => $host->name, 'home' => $home->name]);
        }
        if (! $this->hierarchy->genderFits($host, $person->gender)) {
            $fail('gender');
        }
        $inHome = TeamMembership::query()->where('team_id', $home->id)->where('person_id', $person->id)->where('role', TeamRole::Player)->exists();
        if (! $inHome) {
            $fail('not_in_home');
        }
        if (TeamMembership::query()->where('team_id', $host->id)->where('person_id', $person->id)->exists()) {
            $fail('already_member');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CrossNomination $nomination): array
    {
        return [
            'id' => $nomination->id,
            'active' => $nomination->active,
            'person' => $this->presenter->present($nomination->person, $this->current->get()),
            'home_team' => ['id' => $nomination->homeTeam->id, 'name' => $nomination->homeTeam->name],
        ];
    }
}
