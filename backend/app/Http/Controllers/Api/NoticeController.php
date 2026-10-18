<?php

namespace App\Http\Controllers\Api;

use App\Enums\EventScope;
use App\Http\Controllers\Controller;
use App\Models\Notice;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Support\Auth\CurrentMember;
use App\Support\Auth\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Notice area on the start page: trainers post for their team for a chosen
 * period, admins also for the whole department.
 */
class NoticeController extends Controller
{
    public function __construct(private CurrentMember $current, private Permissions $permissions) {}

    public function index(Request $request): JsonResponse
    {
        $member = $this->current->get();
        $now = Carbon::now();
        $myTeams = TeamMembership::query()->whereIn('person_id', $this->permissions->actingPersonIds($member))->pluck('team_id');

        $query = Notice::query()->with('team')
            ->where(fn ($q) => $q->where('scope', EventScope::Tenant)->orWhereIn('team_id', $myTeams));

        // Managers can also see notices that are not (or no longer) shown.
        $request->boolean('all')
            ? $query->where('visible_until', '>=', $now->copy()->subDays(30))
            : $query->where('visible_from', '<=', $now)->where('visible_until', '>=', $now);

        $notices = $query->orderByDesc('visible_from')->get();

        return response()->json(['data' => $notices->map(fn (Notice $n) => $this->present($n))->values()->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $member = $this->current->get();
        $data = $request->validate([
            'scope' => ['required', Rule::enum(EventScope::class)],
            'team_id' => ['required_if:scope,team', 'nullable', 'string'],
            'title' => ['required', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:2000'],
            'visible_from' => ['required', 'date'],
            'visible_until' => ['required', 'date', 'after:visible_from'],
        ]);

        if ($data['scope'] === EventScope::Tenant->value) {
            abort_unless($this->permissions->isAdmin($member), 403);
            $data['team_id'] = null;
        } else {
            $team = Team::query()->findOrFail($data['team_id']);
            abort_unless($this->permissions->canManageTeam($member, $team), 403);
        }

        $tz = $this->current->get()->tenant->timezone;
        $notice = Notice::create([
            ...$data,
            'visible_from' => Carbon::parse($data['visible_from'], $tz)->utc(),
            'visible_until' => Carbon::parse($data['visible_until'], $tz)->utc(),
            'created_by' => $member->id,
        ]);

        return response()->json(['data' => $this->present($notice->load('team'))], 201);
    }

    public function destroy(Notice $notice): JsonResponse
    {
        $member = $this->current->get();
        $allowed = $this->permissions->isAdmin($member)
            || $notice->created_by === $member->id
            || ($notice->team !== null && $this->permissions->canManageTeam($member, $notice->team));
        abort_unless($allowed, 403);

        $notice->delete();

        return response()->json(['message' => 'ok']);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Notice $notice): array
    {
        $member = $this->current->get();

        return [
            'id' => $notice->id,
            'scope' => $notice->scope->value,
            'team' => $notice->team ? ['id' => $notice->team->id, 'name' => $notice->team->name] : null,
            'title' => $notice->title,
            'body' => $notice->body,
            'visible_from' => $notice->visible_from->toIso8601ZuluString(),
            'visible_until' => $notice->visible_until->toIso8601ZuluString(),
            'can_delete' => $this->permissions->isAdmin($member) || $notice->created_by === $member->id
                || ($notice->team !== null && $this->permissions->canManageTeam($member, $notice->team)),
        ];
    }
}
