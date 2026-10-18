<?php

namespace App\Http\Controllers\Api;

use App\Enums\CrossNominationMode;
use App\Enums\RsvpVisibility;
use App\Enums\TeamGender;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\TenantMember;
use App\Support\Auth\CurrentMember;
use App\Support\Auth\Permissions;
use App\Support\Auth\PersonPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class TeamController extends Controller
{
    /** Basic team data – admin only. */
    private const BASE_FIELDS = ['name', 'gender', 'is_youth', 'rank_in_league', 'color'];

    /** Team settings – admin and the team's trainers. */
    private const SETTING_FIELDS = [
        'min_participants', 'default_max_participants', 'cross_nomination_mode', 'cross_nomination_hours',
        'decline_reason_required', 'maybe_reason_required', 'reasons_visible_to_teammates', 'rsvp_visibility',
    ];

    public function __construct(private CurrentMember $current, private Permissions $permissions) {}

    public function index(): JsonResponse
    {
        $member = $this->current->get();
        $teams = Team::query()->whereNull('archived_at')->orderBy('rank_in_league')->orderBy('name')->get();

        return response()->json(['data' => $teams->map(fn (Team $team) => $this->summary($team, $member))->values()->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($this->permissions->isAdmin($this->current->get()), 403);

        $team = Team::create($request->validate($this->baseRules(required: true)));

        return response()->json(['data' => $this->detail($team)], 201);
    }

    public function show(Team $team): JsonResponse
    {
        abort_unless($this->permissions->canSeeTeam($this->current->get(), $team), 403);

        return response()->json(['data' => $this->detail($team)]);
    }

    public function update(Request $request, Team $team): JsonResponse
    {
        $member = $this->current->get();
        abort_unless($this->permissions->canManageTeam($member, $team), 403);

        $allowed = $this->permissions->isAdmin($member)
            ? array_merge(self::BASE_FIELDS, self::SETTING_FIELDS)
            : self::SETTING_FIELDS;

        $forbidden = array_diff(array_keys($request->only(array_merge(self::BASE_FIELDS, self::SETTING_FIELDS))), $allowed);
        abort_if($forbidden !== [], 403);

        $data = $request->validate(array_intersect_key(
            array_merge($this->baseRules(required: false), $this->settingRules()),
            array_flip($allowed),
        ));

        $team->fill($data)->save();

        return response()->json(['data' => $this->detail($team)]);
    }

    public function destroy(Team $team): JsonResponse
    {
        abort_unless($this->permissions->isAdmin($this->current->get()), 403);

        $team->forceFill(['archived_at' => Carbon::now()])->save();

        return response()->json(['message' => 'ok']);
    }

    /** Roster: everybody in the team with their roles. */
    public function members(Team $team, PersonPresenter $presenter): JsonResponse
    {
        $viewer = $this->current->get();
        abort_unless($this->permissions->canSeeTeam($viewer, $team), 403);

        $memberships = $team->memberships()->with('person')->get()->filter(fn (TeamMembership $m) => $m->person !== null);

        $rows = $memberships->groupBy('person_id')->map(fn ($group) => [
            'person' => $presenter->present($group->first()->person, $viewer),
            'roles' => $group->map(fn (TeamMembership $m) => ['id' => $m->id, 'role' => $m->role->value])->values()->all(),
        ])->sortBy(fn ($row) => mb_strtolower($row['person']['last_name'].' '.$row['person']['first_name']))->values();

        return response()->json(['data' => $rows->all()]);
    }

    public function addMember(Request $request, Team $team): JsonResponse
    {
        abort_unless($this->permissions->canManageTeam($this->current->get(), $team), 403);

        $data = $request->validate([
            'person_id' => ['required', 'string', Rule::exists('persons', 'id')->where('tenant_id', $team->tenant_id)->whereNull('deleted_at')],
            'role' => ['required', Rule::enum(TeamRole::class)],
        ]);

        $membership = TeamMembership::query()->firstOrCreate(
            ['team_id' => $team->id, 'person_id' => $data['person_id'], 'role' => $data['role']],
            ['joined_at' => Carbon::today()],
        );

        return response()->json(['data' => ['id' => $membership->id]], 201);
    }

    public function removeMember(Team $team, TeamMembership $membership): JsonResponse
    {
        abort_unless($this->permissions->canManageTeam($this->current->get(), $team), 403);
        abort_unless($membership->team_id === $team->id, 404);

        $membership->delete();

        return response()->json(['message' => 'ok']);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Team $team, TenantMember $member): array
    {
        return [
            'id' => $team->id,
            'name' => $team->name,
            'gender' => $team->gender->value,
            'is_youth' => $team->is_youth,
            'color' => $team->color,
            'can_manage' => $this->permissions->canManageTeam($member, $team),
            'can_invite' => $this->permissions->canInviteToTeam($member, $team),
            'can_see' => $this->permissions->canSeeTeam($member, $team),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Team $team): array
    {
        return $this->summary($team, $this->current->get()) + [
            'rank_in_league' => $team->rank_in_league,
            'settings' => [
                'min_participants' => $team->min_participants,
                'default_max_participants' => $team->default_max_participants,
                'cross_nomination_mode' => $team->cross_nomination_mode->value,
                'cross_nomination_hours' => $team->cross_nomination_hours,
                'decline_reason_required' => $team->decline_reason_required,
                'maybe_reason_required' => $team->maybe_reason_required,
                'reasons_visible_to_teammates' => $team->reasons_visible_to_teammates,
                'rsvp_visibility' => $team->rsvp_visibility->value,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function baseRules(bool $required): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            'name' => [$presence, 'string', 'max:80'],
            'gender' => [$presence, Rule::enum(TeamGender::class)],
            'is_youth' => ['sometimes', 'boolean'],
            'rank_in_league' => ['sometimes', 'integer', 'between:1,99'],
            'color' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function settingRules(): array
    {
        return [
            'min_participants' => ['sometimes', 'nullable', 'integer', 'between:1,99'],
            'default_max_participants' => ['sometimes', 'nullable', 'integer', 'between:1,99'],
            'cross_nomination_mode' => ['sometimes', Rule::enum(CrossNominationMode::class)],
            'cross_nomination_hours' => ['sometimes', 'nullable', 'integer', 'between:1,336'],
            'decline_reason_required' => ['sometimes', 'boolean'],
            'maybe_reason_required' => ['sometimes', 'boolean'],
            'reasons_visible_to_teammates' => ['sometimes', 'boolean'],
            'rsvp_visibility' => ['sometimes', Rule::enum(RsvpVisibility::class)],
        ];
    }
}
