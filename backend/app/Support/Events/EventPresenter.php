<?php

namespace App\Support\Events;

use App\Enums\EventType;
use App\Enums\RsvpStatus;
use App\Enums\RsvpVisibility;
use App\Enums\TeamRole;
use App\Models\Event;
use App\Models\EventInvitee;
use App\Models\EventResponse;
use App\Models\Person;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\TenantMember;
use App\Support\Auth\Permissions;
use App\Support\Teams\GuestWindow;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Event data for one viewer.
 *
 * Who sees whom: people managing the event (trainers, organizers, admins)
 * always see names; teammates see names or only the numbers, as the team
 * decided (several teams: the stricter setting). Reasons: trainers and
 * admins always, everybody else only if all teams of the event allow it.
 */
class EventPresenter
{
    public function __construct(
        private Permissions $permissions,
        private RsvpService $rsvp,
        private TenantContext $tenants,
        private GuestWindow $guests,
    ) {}

    /**
     * @param  Collection<int, Event>  $events
     * @return list<array<string, mixed>>
     */
    public function list(Collection $events, TenantMember $viewer): array
    {
        $events = (new EloquentCollection($events->all()))->loadMissing(['teams', 'opponent', 'trainingGroup']);
        $ids = $events->pluck('id');

        $counts = EventResponse::query()->whereIn('event_id', $ids)
            ->selectRaw('event_id, status, count(*) as n')->groupBy('event_id', 'status')->toBase()->get()
            ->groupBy('event_id');
        $invited = EventInvitee::query()->whereIn('event_id', $ids)
            ->selectRaw('event_id, count(*) as n')->groupBy('event_id')->toBase()->pluck('n', 'event_id');

        $taskSlots = Task::query()->whereIn('event_id', $ids)->selectRaw('event_id, sum(slots) as slots, count(*) as n')->groupBy('event_id')->toBase()->get()->keyBy('event_id');
        $taskTaken = TaskAssignment::query()->join('tasks', 'tasks.id', '=', 'task_assignments.task_id')
            ->whereIn('tasks.event_id', $ids)->selectRaw('tasks.event_id as event_id, count(*) as n')->groupBy('tasks.event_id')->toBase()->pluck('n', 'event_id');

        $acting = $this->actingPersons($viewer);
        $myInvites = EventInvitee::query()->whereIn('event_id', $ids)->whereIn('person_id', $acting->keys())->get()->groupBy('event_id');
        $myResponses = EventResponse::query()->whereIn('event_id', $ids)->whereIn('person_id', $acting->keys())->get()->groupBy('event_id');

        return $events->map(fn (Event $event) => [...$this->summary(
            $event,
            $viewer,
            $counts->get($event->id, collect())->pluck('n', 'status')->map(fn ($n) => (int) $n)->all(),
            (int) ($invited[$event->id] ?? 0),
            $acting,
            $myInvites->get($event->id, collect()),
            $myResponses->get($event->id, collect()),
        ), 'task_summary' => isset($taskSlots[$event->id]) ? [
            'count' => (int) $taskSlots[$event->id]->n,
            'slots' => (int) $taskSlots[$event->id]->slots,
            'taken' => (int) ($taskTaken[$event->id] ?? 0),
        ] : null])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Event $event, TenantMember $viewer): array
    {
        $data = $this->list(collect([$event]), $viewer)[0];

        $canManage = $data['can_manage'];
        $showNames = $canManage || $this->namesVisible($event);
        $showReasons = $this->permissions->isAdmin($viewer)
            || $event->teams->contains(fn (Team $t) => $this->permissions->isTrainerOf($viewer, $t))
            || $event->teams->every(fn (Team $t) => $t->reasons_visible_to_teammates);

        $data['description'] = $event->description;
        $data['names_visible'] = $showNames;
        $data['series_id'] = $event->series_id;
        $data['series'] = $event->series ? [
            'weekdays' => array_map('intval', $event->series->weekdays),
            'interval_weeks' => $event->series->interval_weeks,
            'ends_on' => $event->series->ends_on?->toDateString(),
        ] : null;
        $data['team_ids'] = $event->teams->pluck('id')->all();
        $data['cross_nomination_opens_at'] = $event->cross_nomination_opens_at?->toIso8601ZuluString();
        $data['guests_open_at'] = $this->guests->opensAt($event)?->toIso8601ZuluString();
        $data['responses'] = null;
        $data['positions'] = null;
        $data['tasks'] = $this->tasks($event, $viewer, $canManage);
        // Places taken per limited position – numbers only, visible to everybody invited.
        $data['position_taken'] = $event->position_limits ? EventResponse::query()->where('event_id', $event->id)
            ->where('status', RsvpStatus::Yes)->whereNotNull('as_position')
            ->selectRaw('as_position, count(*) as n')->groupBy('as_position')->toBase()->pluck('n', 'as_position')
            ->map(fn ($n) => (int) $n)->all() : null;

        if ($showNames) {
            $invitees = EventInvitee::query()->where('event_id', $event->id)->pluck('source', 'person_id');
            $persons = Person::query()->whereIn('id', $invitees->keys())->get()->keyBy('id');
            $responses = EventResponse::query()->where('event_id', $event->id)->get()->keyBy('person_id');
            $roles = $this->rolesInEvent($event);

            $data['responses'] = $persons->map(function (Person $person) use ($responses, $showReasons, $roles, $invitees) {
                $response = $responses->get($person->id);

                return [
                    'person' => [
                        'id' => $person->id,
                        'name' => $person->first_name.' '.$person->last_name,
                        'avatar_url' => $person->avatar_file_id ? $this->fileUrl($person->avatar_file_id) : null,
                        'primary_position' => $person->primary_position?->value,
                        'positions' => array_values(array_unique(array_filter([$person->primary_position?->value, ...($person->secondary_positions ?? [])]))),
                        'role' => $roles[$person->id] ?? TeamRole::Player->value,
                        'is_guest' => $invitees[$person->id] === 'cross_nomination',
                    ],
                    'status' => $response?->status->value,
                    'as_position' => $response?->as_position,
                    'reason' => $showReasons ? $response?->reason : null,
                    'responded_at' => $response?->responded_at?->toIso8601ZuluString(),
                ];
            })->sortBy(fn ($row) => mb_strtolower($row['person']['name']))->values()->all();

            // Who is coming per position (players only), so trainers can plan the training.
            if ($canManage) {
                $data['positions'] = collect($data['responses'])
                    ->filter(fn ($row) => $row['status'] === RsvpStatus::Yes->value && $row['person']['role'] === TeamRole::Player->value)
                    ->countBy(fn ($row) => $row['as_position'] ?? $row['person']['primary_position'] ?? 'unknown')
                    ->all();
            }
        }

        return $data;
    }

    /**
     * @param  array<string, int>  $counts
     * @param  Collection<int, Person>  $acting
     * @param  Collection<int, EventInvitee>  $myInvites
     * @param  Collection<int, EventResponse>  $myResponses
     * @return array<string, mixed>
     */
    private function summary(Event $event, TenantMember $viewer, array $counts, int $invited, Collection $acting, Collection $myInvites, Collection $myResponses): array
    {
        $yes = $counts['yes'] ?? 0;
        $answered = array_sum($counts);
        $minimum = $event->type === EventType::Training ? ($event->min_participants ?? $event->teams->pluck('min_participants')->filter()->max()) : null;
        $canManage = $this->permissions->canManageEvent($viewer, $event);
        $deadlinePassed = $event->deadlinePassed();

        $tenant = $this->tenants->get();
        $team = $event->teams->first();

        return [
            'id' => $event->id,
            'type' => $event->type->value,
            'response_mode' => $event->response_mode->value,
            'scope' => $event->scope->value,
            'status' => $event->status->value,
            'title' => $event->title,
            'starts_at' => $event->starts_at->toIso8601ZuluString(),
            'ends_at' => $event->ends_at->toIso8601ZuluString(),
            'meet_at' => $event->meet_at?->toIso8601ZuluString(),
            'rsvp_deadline' => $event->rsvp_deadline?->toIso8601ZuluString(),
            'deadline_passed' => $deadlinePassed,
            'location_name' => $event->location_name,
            'address' => $event->address,
            'directions_url' => $event->directionsUrl(),
            'teams' => $event->teams->map(fn (Team $t) => ['id' => $t->id, 'name' => $t->name, 'color' => $t->color])->values()->all(),
            'opponent' => $event->opponent ? ['id' => $event->opponent->id, 'name' => $event->opponent->name] : null,
            'is_home' => $event->is_home,
            // Logos: own team (or department), opponent.
            'own_logo_url' => $team?->logo_file_id ? $this->fileUrl($team->logo_file_id) : ($tenant->logo_file_id ? $this->tenantLogoUrl() : null),
            'opponent_logo_url' => $event->opponent?->logo_file_id ? $this->fileUrl($event->opponent->logo_file_id) : null,
            'counts' => [
                'yes' => $yes,
                'no' => $counts['no'] ?? 0,
                'maybe' => $counts['maybe'] ?? 0,
                'open' => max(0, $invited - $answered),
            ],
            'max_participants' => $event->max_participants,
            'own_min_participants' => $event->min_participants,
            'guests_allowed' => $event->guests_allowed,
            'position_limits' => $event->position_limits,
            'is_full' => $event->max_participants !== null && $yes >= $event->max_participants,
            'min_participants' => $minimum,
            'below_minimum' => $minimum !== null && $event->expectsResponses() && $yes < $minimum,
            'can_manage' => $canManage,
            'reason_required' => [
                'no' => $this->rsvp->reasonRequired($event, RsvpStatus::No),
                'maybe' => $this->rsvp->reasonRequired($event, RsvpStatus::Maybe),
            ],
            'training_group' => $event->trainingGroup ? ['id' => $event->trainingGroup->id, 'name' => $event->trainingGroup->name] : null,
            'my' => $myInvites->map(function (EventInvitee $invite) use ($acting, $myResponses, $viewer, $event, $deadlinePassed, $canManage) {
                $person = $acting[$invite->person_id];
                $response = $myResponses->firstWhere('person_id', $invite->person_id);
                $guest = $invite->source === 'cross_nomination';
                $closed = $guest ? $this->guests->isClosed($event) : $deadlinePassed;

                return [
                    'person_id' => $person->id,
                    'name' => $person->first_name,
                    'is_self' => $person->id === $this->permissions->ownPersonId($viewer),
                    'status' => $response?->status->value,
                    'reason' => $response?->reason,
                    'as_position' => $response?->as_position,
                    'positions' => array_values(array_unique(array_filter([$person->primary_position?->value, ...($person->secondary_positions ?? [])]))),
                    'is_guest' => $guest,
                    // Guests may decline any time, accept only once places open up for them.
                    'can_accept' => $canManage || ! $guest || $this->guests->isOpen($event),
                    'guests_open_at' => $guest ? $this->guests->opensAt($event)?->toIso8601ZuluString() : null,
                    'can_respond' => $event->expectsResponses()
                        && ($canManage || (! $closed && $this->permissions->canRespondFor($viewer, $person))),
                ];
            })->values()->all(),
        ];
    }

    /**
     * Tasks with who took them (names are visible to everybody invited –
     * that is the point of "who drives?") and what the viewer can do.
     *
     * @return list<array<string, mixed>>
     */
    private function tasks(Event $event, TenantMember $viewer, bool $canManage): array
    {
        $tasks = $event->tasks()->with('assignments.person')->get();
        if ($tasks->isEmpty()) {
            return [];
        }
        $acting = $this->actingPersons($viewer);
        $invited = EventInvitee::query()->where('event_id', $event->id)->whereIn('person_id', $acting->keys())->pluck('person_id');
        $open = ! $event->isCancelled() && $event->starts_at->isFuture();

        return $tasks->map(fn (Task $task) => [
            'id' => $task->id,
            'title' => $task->title,
            'description' => $task->description,
            'slots' => $task->slots,
            'assignees' => $task->assignments->map(fn (TaskAssignment $a) => [
                'person_id' => $a->person_id,
                'name' => $a->person ? $a->person->first_name.' '.$a->person->last_name : '–',
                'mine' => $acting->has($a->person_id),
            ])->values()->all(),
            // Whom the viewer can put on this task (self, children).
            'can_take_for' => ($open || $canManage) ? $invited
                ->filter(fn ($id) => $canManage || $this->permissions->canTakeTasksFor($viewer, $acting[$id]))
                ->map(fn ($id) => ['person_id' => $id, 'name' => $acting[$id]->first_name])
                ->values()->all() : [],
        ])->values()->all();
    }

    private function namesVisible(Event $event): bool
    {
        return $event->teams->every(fn (Team $t) => $t->rsvp_visibility === RsvpVisibility::Names);
    }

    /**
     * @return Collection<string, Person>
     */
    private function actingPersons(TenantMember $viewer): Collection
    {
        $ids = $this->permissions->links($viewer)->pluck('person_id');

        return Person::query()->with('minorPermission')->whereIn('id', $ids)->get()->keyBy('id');
    }

    /**
     * Highest role per person within the event's teams (trainer > organizer > player).
     *
     * @return array<string, string>
     */
    private function rolesInEvent(Event $event): array
    {
        $order = [TeamRole::Trainer->value => 0, TeamRole::Organizer->value => 1, TeamRole::Player->value => 2];

        return TeamMembership::query()
            ->whereIn('team_id', $event->teams->pluck('id'))
            ->get()
            ->groupBy('person_id')
            ->map(fn ($memberships) => $memberships->map(fn ($m) => $m->role->value)->sortBy(fn ($r) => $order[$r])->first())
            ->all();
    }

    private function fileUrl(string $fileId): string
    {
        return '/api/v1/t/'.$this->tenants->get()->slug.'/files/'.$fileId;
    }

    private function tenantLogoUrl(): string
    {
        return '/api/v1/t/'.$this->tenants->get()->slug.'/logo';
    }
}
