<?php

namespace App\Http\Controllers\Api;

use App\Enums\EventScope;
use App\Enums\EventType;
use App\Enums\Position;
use App\Enums\RsvpStatus;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventInvitee;
use App\Models\EventTeam;
use App\Models\Person;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\TenantMember;
use App\Models\TrainingGroup;
use App\Support\Auth\CurrentMember;
use App\Support\Auth\Permissions;
use App\Support\Events\EventPresenter;
use App\Support\Events\EventService;
use App\Support\Events\RsvpService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EventController extends Controller
{
    private const PAGE_SIZE = 20;

    public function __construct(
        private CurrentMember $current,
        private Permissions $permissions,
        private EventPresenter $presenter,
    ) {}

    /**
     * Start page list: upcoming events of the member (own profile and
     * children) and of the teams they manage. Cursor pagination for
     * infinite scrolling; ?past=1 lists the past instead (newest first).
     */
    public function index(Request $request): JsonResponse
    {
        $member = $this->current->get();
        $past = $request->boolean('past');
        $query = $this->visibleEvents($member, $request->query('team'));

        $now = Carbon::now();
        $past ? $query->where('ends_at', '<', $now) : $query->where('ends_at', '>=', $now);

        $cursor = $this->decodeCursor((string) $request->query('cursor', ''));
        if ($cursor !== null) {
            [$at, $id] = $cursor;
            $op = $past ? '<' : '>';
            $query->where(fn ($q) => $q->where('starts_at', $op, $at)->orWhere(fn ($q) => $q->where('starts_at', $at)->where('id', $op, $id)));
        }

        $direction = $past ? 'desc' : 'asc';
        $events = $query->orderBy('starts_at', $direction)->orderBy('id', $direction)->limit(self::PAGE_SIZE + 1)->get();

        $hasMore = $events->count() > self::PAGE_SIZE;
        $events = $events->take(self::PAGE_SIZE);
        $last = $events->last();

        return response()->json([
            'data' => $this->presenter->list($events, $member),
            'next_cursor' => $hasMore && $last ? base64_encode($last->starts_at->toDateTimeString().'|'.$last->id) : null,
        ]);
    }

    public function show(Event $event): JsonResponse
    {
        $member = $this->current->get();
        abort_unless($this->canSee($member, $event), 403);

        return response()->json(['data' => $this->presenter->detail($event, $member)]);
    }

    public function store(Request $request, EventService $events): JsonResponse
    {
        $member = $this->current->get();
        $data = $request->validate($this->rules(creating: true));

        $scope = EventScope::from($data['scope'] ?? 'team');
        if ($scope === EventScope::Tenant) {
            abort_unless($this->permissions->isAdmin($member), 403);
            $teams = Team::query()->whereNull('archived_at')->get();
        } else {
            $teams = Team::query()->whereIn('id', $data['team_ids'] ?? [])->whereNull('archived_at')->get();
            abort_if($teams->isEmpty() || $teams->count() !== count(array_unique($data['team_ids'])), 422, __('events.teams_invalid'));
            abort_unless($teams->every(fn (Team $t) => $this->permissions->canManageEventsOf($member, $t)), 403);
        }

        $repeat = $data['repeat'] ?? null;
        abort_if($repeat !== null && ($data['type'] !== EventType::Training->value || $teams->count() !== 1), 422, __('events.series_only_training'));
        $this->checkTrainingGroup($data['training_group_id'] ?? null, $teams);

        $event = $events->create($data, $teams, $scope, $member, $repeat);

        return response()->json(['data' => $this->presenter->detail($event->refresh(), $member)], 201);
    }

    /**
     * Changes one event; with ?series=1 the whole series from now on.
     */
    public function update(Request $request, Event $event, EventService $events): JsonResponse
    {
        $member = $this->current->get();
        abort_unless($this->permissions->canManageEvent($member, $event), 403);

        if ($request->boolean('series') && $event->series_id !== null) {
            $data = $request->validate($this->rules(creating: true) + ['repeat' => ['required', 'array']]);
            $this->checkTrainingGroup($data['training_group_id'] ?? null, $event->teams);
            $events->updateSeries($event->series, $data, $data['repeat'], $member);
        } else {
            $data = $request->validate($this->rules(creating: false));
            $this->checkTrainingGroup($data['training_group_id'] ?? null, $event->teams);
            $events->update($event, $data, $member);
        }

        return response()->json(['data' => $this->presenter->detail($event->refresh(), $member)]);
    }

    public function cancel(Request $request, Event $event, EventService $events): JsonResponse
    {
        $member = $this->current->get();
        abort_unless($this->permissions->canManageEvent($member, $event), 403);

        $request->boolean('restore') ? $events->restore($event, $member) : $events->cancel($event, $member);

        return response()->json(['data' => $this->presenter->detail($event->refresh(), $this->current->get())]);
    }

    /** Ends a training series: upcoming dates are cancelled. */
    public function endSeries(Event $event, EventService $events): JsonResponse
    {
        $member = $this->current->get();
        abort_unless($this->permissions->canManageEvent($member, $event) && $event->series_id !== null, 403);

        $events->endSeries($event->series, $member);

        return response()->json(['message' => 'ok']);
    }

    public function respond(Request $request, Event $event, RsvpService $rsvp): JsonResponse
    {
        $member = $this->current->get();
        $data = $request->validate([
            'person_id' => ['required', 'string'],
            'status' => ['required', Rule::enum(RsvpStatus::class)],
            'reason' => ['nullable', 'string', 'max:500'],
            'position' => ['nullable', Rule::enum(Position::class)],
        ]);

        $person = Person::query()->findOrFail($data['person_id']);
        $rsvp->respond($event, $person, RsvpStatus::from($data['status']), $data['reason'] ?? null, $member, $data['position'] ?? null);

        return response()->json(['data' => $this->presenter->detail($event->refresh(), $member)]);
    }

    /**
     * Who was really there (after the start): the listed persons present,
     * all other invitees absent. Trainers of the event's teams and admins.
     */
    public function attendance(Request $request, Event $event): JsonResponse
    {
        $member = $this->current->get();
        abort_unless($this->permissions->canRecordAttendance($member, $event), 403);
        abort_unless($event->expectsResponses() && $event->starts_at->lessThanOrEqualTo(Carbon::now()), 422, __('events.attendance_not_yet'));
        $data = $request->validate(['present' => ['present', 'array'], 'present.*' => ['string']]);
        $present = array_flip($data['present']);

        DB::transaction(function () use ($event, $present) {
            foreach (EventInvitee::query()->where('event_id', $event->id)->get() as $invitee) {
                $invitee->forceFill(['attended' => isset($present[$invitee->person_id])])->save();
            }
            $event->forceFill(['attendance_recorded_at' => Carbon::now()])->save();
        });

        return response()->json(['data' => $this->presenter->detail($event->refresh(), $member)]);
    }

    /**
     * Events in the member's list: invited (self/children) or of teams they
     * manage. With ?team= all events of one team the member can see.
     *
     * @return Builder<Event>
     */
    private function visibleEvents(TenantMember $member, ?string $teamId): Builder
    {
        $query = Event::query();

        if ($teamId) {
            $team = Team::query()->findOrFail($teamId);
            abort_unless($this->permissions->canSeeTeam($member, $team), 403);

            return $query->whereIn('id', EventTeam::query()->where('team_id', $team->id)->select('event_id'));
        }

        $managedTeams = $this->managedTeamIds($member);

        return $query->where(fn ($q) => $q
            ->whereIn('id', EventInvitee::query()->whereIn('person_id', $this->permissions->actingPersonIds($member))->select('event_id'))
            ->orWhereIn('id', EventTeam::query()->whereIn('team_id', $managedTeams)->select('event_id')));
    }

    /**
     * @return Collection<int, string>
     */
    private function managedTeamIds(TenantMember $member): Collection
    {
        return $this->permissions->ownMemberships($member)
            ->filter(fn (TeamMembership $m) => in_array($m->role->value, ['trainer', 'organizer'], true))
            ->pluck('team_id')->values();
    }

    /**
     * A training group must belong to the (single) team of the event.
     *
     * @param  Collection<int, Team>  $teams
     */
    private function checkTrainingGroup(?string $groupId, Collection $teams): void
    {
        if ($groupId === null) {
            return;
        }

        $valid = $teams->count() === 1
            && TrainingGroup::query()->where('id', $groupId)->where('team_id', $teams->first()->id)->exists();
        abort_unless($valid, 422, __('teams.group_invalid'));
    }

    private function canSee(TenantMember $member, Event $event): bool
    {
        if ($this->permissions->isAdmin($member) || $this->permissions->canManageEvent($member, $event)) {
            return true;
        }

        return EventInvitee::query()->where('event_id', $event->id)->whereIn('person_id', $this->permissions->actingPersonIds($member))->exists()
            || $event->teams->contains(fn (Team $t) => $this->permissions->canSeeTeam($member, $t));
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function decodeCursor(string $cursor): ?array
    {
        $decoded = base64_decode($cursor, true);
        if ($decoded === false || ! str_contains($decoded, '|')) {
            return null;
        }
        [$at, $id] = explode('|', $decoded, 2);

        return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $at) && preg_match('/^[0-9A-Za-z]{26}$/', $id) ? [$at, $id] : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'type' => [$required, Rule::in(['training', 'match', 'extra', 'helper'])],
            'response_mode' => ['sometimes', Rule::in(['rsvp', 'info'])],
            'scope' => ['sometimes', Rule::enum(EventScope::class)],
            'team_ids' => ['sometimes', 'array', 'max:50'],
            'team_ids.*' => ['string'],
            'title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'starts_at' => [$required, 'date'],
            'ends_at' => [$required, 'date', 'after:starts_at'],
            'meet_at' => ['sometimes', 'nullable', 'date', 'before_or_equal:starts_at'],
            'rsvp_deadline' => ['sometimes', 'nullable', 'date', 'before_or_equal:starts_at'],
            'location_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'max_participants' => ['sometimes', 'nullable', 'integer', 'between:1,500'],
            'position_limits' => ['sometimes', 'nullable', 'array:'.implode(',', array_column(Position::cases(), 'value'))],
            'position_limits.*' => ['nullable', 'integer', 'between:0,50'],
            'opponent_id' => ['sometimes', 'nullable', 'string', Rule::exists('opponents', 'id')->where('tenant_id', $this->current->get()->tenant_id)],
            'is_home' => ['sometimes', 'nullable', 'boolean'],
            'cross_nomination_opens_at' => ['sometimes', 'nullable', 'date'],
            'guests_allowed' => ['sometimes', 'boolean'],
            'guests_positions_only' => ['sometimes', 'boolean'],
            'min_participants' => ['sometimes', 'nullable', 'integer', 'between:1,500'],
            'training_group_id' => ['sometimes', 'nullable', 'string'],
            'repeat' => ['sometimes', 'nullable', 'array'],
            'repeat.weekdays' => ['required_with:repeat', 'array', 'min:1'],
            'repeat.weekdays.*' => ['integer', 'between:1,7'],
            'repeat.interval_weeks' => ['sometimes', 'integer', 'between:1,4'],
            'repeat.ends_on' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
