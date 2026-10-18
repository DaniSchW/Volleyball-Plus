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
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\TenantMember;
use App\Support\Auth\Permissions;
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
    ) {}

    /**
     * @param  Collection<int, Event>  $events
     * @return list<array<string, mixed>>
     */
    public function list(Collection $events, TenantMember $viewer): array
    {
        $events = (new EloquentCollection($events->all()))->loadMissing(['teams', 'opponent']);
        $ids = $events->pluck('id');

        $counts = EventResponse::query()->whereIn('event_id', $ids)
            ->selectRaw('event_id, status, count(*) as n')->groupBy('event_id', 'status')->toBase()->get()
            ->groupBy('event_id');
        $invited = EventInvitee::query()->whereIn('event_id', $ids)
            ->selectRaw('event_id, count(*) as n')->groupBy('event_id')->toBase()->pluck('n', 'event_id');

        $acting = $this->actingPersons($viewer);
        $myInvites = EventInvitee::query()->whereIn('event_id', $ids)->whereIn('person_id', $acting->keys())->get()->groupBy('event_id');
        $myResponses = EventResponse::query()->whereIn('event_id', $ids)->whereIn('person_id', $acting->keys())->get()->groupBy('event_id');

        return $events->map(fn (Event $event) => $this->summary(
            $event,
            $viewer,
            $counts->get($event->id, collect())->pluck('n', 'status')->map(fn ($n) => (int) $n)->all(),
            (int) ($invited[$event->id] ?? 0),
            $acting,
            $myInvites->get($event->id, collect()),
            $myResponses->get($event->id, collect()),
        ))->values()->all();
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
        $data['reason_required'] = [
            'no' => $this->rsvp->reasonRequired($event, RsvpStatus::No),
            'maybe' => $this->rsvp->reasonRequired($event, RsvpStatus::Maybe),
        ];
        $data['series_id'] = $event->series_id;
        $data['team_ids'] = $event->teams->pluck('id')->all();
        $data['responses'] = null;

        if ($showNames) {
            $invitees = Person::query()->whereIn('id', EventInvitee::query()->where('event_id', $event->id)->pluck('person_id'))->get()->keyBy('id');
            $responses = EventResponse::query()->where('event_id', $event->id)->get()->keyBy('person_id');
            $roles = $this->rolesInEvent($event);

            $data['responses'] = $invitees->map(function (Person $person) use ($responses, $showReasons, $roles) {
                $response = $responses->get($person->id);

                return [
                    'person' => [
                        'id' => $person->id,
                        'name' => $person->first_name.' '.$person->last_name,
                        'avatar_url' => $person->avatar_file_id ? $this->fileUrl($person->avatar_file_id) : null,
                        'primary_position' => $person->primary_position?->value,
                        'role' => $roles[$person->id] ?? TeamRole::Player->value,
                    ],
                    'status' => $response?->status->value,
                    'reason' => $showReasons ? $response?->reason : null,
                    'responded_at' => $response?->responded_at?->toIso8601ZuluString(),
                ];
            })->sortBy(fn ($row) => mb_strtolower($row['person']['name']))->values()->all();
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
        $minimum = $event->type === EventType::Training ? $event->teams->pluck('min_participants')->filter()->max() : null;
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
            'is_full' => $event->max_participants !== null && $yes >= $event->max_participants,
            'min_participants' => $minimum,
            'below_minimum' => $minimum !== null && $event->expectsResponses() && $yes < $minimum,
            'can_manage' => $canManage,
            'my' => $myInvites->map(function (EventInvitee $invite) use ($acting, $myResponses, $viewer, $event, $deadlinePassed, $canManage) {
                $person = $acting[$invite->person_id];
                $response = $myResponses->firstWhere('person_id', $invite->person_id);

                return [
                    'person_id' => $person->id,
                    'name' => $person->first_name,
                    'is_self' => $person->id === $this->permissions->ownPersonId($viewer),
                    'status' => $response?->status->value,
                    'reason' => $response?->reason,
                    'can_respond' => $event->expectsResponses()
                        && ($canManage || (! $deadlinePassed && $this->permissions->canRespondFor($viewer, $person))),
                ];
            })->values()->all(),
        ];
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
