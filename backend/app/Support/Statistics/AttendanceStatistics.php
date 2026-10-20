<?php

namespace App\Support\Statistics;

use App\Enums\EventStatus;
use App\Enums\EventType;
use App\Enums\ResponseMode;
use App\Enums\RsvpStatus;
use App\Enums\TeamRole;
use App\Models\Event;
use App\Models\EventInvitee;
use App\Models\EventResponse;
use App\Models\EventResponseLog;
use App\Models\Person;
use App\Models\TaskAssignment;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Reply rates of a team's people in a period: for every past event of the
 * team that took replies (not cancelled) and to which the person was
 * invited – accepted, maybe, declined, no reaction at all. Rate =
 * accepted / invited. Answers given or changed after the deadline are
 * counted too. Training attendance = trainings present / trainings
 * invited; present is what the trainer recorded afterwards, else an
 * acceptance.
 *
 * People who are only trainers or organizers of the team are left out;
 * former players and cross-nominated guests stay in (they were invited).
 */
class AttendanceStatistics
{
    public const TYPES = ['training', 'match', 'extra'];

    public function __construct(private TenantContext $tenants) {}

    /**
     * Default periods in the department's time zone: last 4 weeks and the
     * current season (from the 1st of the season's start month to its end –
     * rates only count past events anyway, tasks also upcoming ones).
     *
     * @return array{last_4_weeks: array{from: string, to: string}, season: array{from: string, to: string}}
     */
    public function presets(?CarbonImmutable $today = null): array
    {
        $tenant = $this->tenants->get();
        $today ??= CarbonImmutable::now($tenant->timezone)->startOfDay();
        $season = $today->setDate($today->year, $tenant->season_start_month, 1);
        if ($season->greaterThan($today)) {
            $season = $season->subYear();
        }

        return [
            'last_4_weeks' => ['from' => $today->subWeeks(4)->toDateString(), 'to' => $today->toDateString()],
            'season' => ['from' => $season->toDateString(), 'to' => $season->addYear()->subDay()->toDateString()],
        ];
    }

    /**
     * @param  list<string>  $types
     * @return Collection<int, Event>
     */
    public function events(Team $team, string $from, string $to, array $types): Collection
    {
        $tz = $this->tenants->get()->timezone;
        $start = CarbonImmutable::parse($from, $tz)->startOfDay()->utc();
        $end = CarbonImmutable::parse($to, $tz)->endOfDay()->utc()->min(Carbon::now());

        return Event::query()
            ->whereIn('id', fn ($q) => $q->select('event_id')->from('event_teams')->where('team_id', $team->id))
            ->where('response_mode', ResponseMode::Rsvp)
            ->where('status', EventStatus::Scheduled)
            ->whereIn('type', $types)
            ->where('starts_at', '>=', $start)
            ->where('starts_at', '<=', $end)
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * @param  Collection<int, Event>  $events
     * @param  list<string>|null  $onlyPersons  limit to these persons (players see only themselves)
     * @return list<array<string, mixed>>
     */
    public function rows(Team $team, Collection $events, ?array $onlyPersons = null): array
    {
        $eventIds = $events->pluck('id');
        $invitees = EventInvitee::query()->whereIn('event_id', $eventIds)
            ->when($onlyPersons !== null, fn ($q) => $q->whereIn('person_id', $onlyPersons))
            ->get(['event_id', 'person_id', 'source', 'attended']);
        $trainings = $events->filter(fn (Event $e) => $e->type === EventType::Training)->pluck('id')->flip();

        $staffOnly = $this->staffOnly($team);
        $invitees = $invitees->reject(fn (EventInvitee $i) => $staffOnly->has($i->person_id));

        $responses = EventResponse::query()->whereIn('event_id', $eventIds)->whereIn('person_id', $invitees->pluck('person_id')->unique())
            ->get(['event_id', 'person_id', 'status'])->keyBy(fn ($r) => $r->event_id.'|'.$r->person_id);
        $late = EventResponseLog::query()->whereIn('event_id', $eventIds)->where('after_deadline', true)
            ->selectRaw('person_id, count(distinct event_id) as n')->groupBy('person_id')->toBase()->pluck('n', 'person_id');

        $persons = Person::withTrashed()->whereIn('id', $invitees->pluck('person_id')->unique())->get()->keyBy('id');
        $current = TeamMembership::query()->where('team_id', $team->id)->where('role', TeamRole::Player)->pluck('person_id')->flip();

        return $invitees->groupBy('person_id')->map(function (Collection $rows, string $personId) use ($responses, $late, $persons, $current, $trainings) {
            $counts = ['yes' => 0, 'maybe' => 0, 'no' => 0, 'open' => 0];
            $training = ['invited' => 0, 'present' => 0];
            foreach ($rows as $invite) {
                $status = $responses->get($invite->event_id.'|'.$personId)?->status->value ?? 'open';
                $counts[$status]++;
                if ($trainings->has($invite->event_id)) {
                    $training['invited']++;
                    $training['present'] += ($invite->attended ?? $status === 'yes') ? 1 : 0;
                }
            }
            $person = $persons->get($personId);
            $invited = $rows->count();

            return [
                'person' => [
                    'id' => $personId,
                    'name' => $person ? $person->first_name.' '.$person->last_name : '–',
                    'primary_position' => $person?->primary_position?->value,
                ],
                'is_guest' => $rows->every(fn ($i) => $i->source === 'cross_nomination'),
                'is_current' => $current->has($personId),
                'invited' => $invited,
                ...$counts,
                'late' => (int) ($late[$personId] ?? 0),
                'rate' => $invited > 0 ? (int) round($counts['yes'] * 100 / $invited) : 0,
                // "open" of past events = did not react at all.
                'no_reaction' => $counts['open'],
                'trainings' => $training['invited'],
                'trainings_present' => $training['present'],
                'attendance_rate' => $training['invited'] > 0 ? (int) round($training['present'] * 100 / $training['invited']) : null,
            ];
        })
            ->sortBy([['rate', 'desc'], ['invited', 'desc'], fn ($a, $b) => strcmp(mb_strtolower($a['person']['name']), mb_strtolower($b['person']['name']))])
            ->values()->all();
    }

    /**
     * Tasks taken per person on the team's events in the period (helper
     * services counted separately). Upcoming events count too – what
     * matters is who offered to help.
     *
     * @param  list<string>|null  $onlyPersons
     * @return list<array<string, mixed>>
     */
    public function taskRows(Team $team, string $from, string $to, ?array $onlyPersons = null): array
    {
        $tz = $this->tenants->get()->timezone;
        $rows = TaskAssignment::query()
            ->join('tasks', 'tasks.id', '=', 'task_assignments.task_id')
            ->join('events', 'events.id', '=', 'tasks.event_id')
            ->where('task_assignments.tenant_id', $this->tenants->id())
            ->whereIn('events.id', fn ($q) => $q->select('event_id')->from('event_teams')->where('team_id', $team->id))
            ->where('events.status', EventStatus::Scheduled->value)
            ->where('events.starts_at', '>=', CarbonImmutable::parse($from, $tz)->startOfDay()->utc())
            ->where('events.starts_at', '<=', CarbonImmutable::parse($to, $tz)->endOfDay()->utc())
            ->when($onlyPersons !== null, fn ($q) => $q->whereIn('task_assignments.person_id', $onlyPersons))
            ->selectRaw("task_assignments.person_id as person_id, sum(case when events.type = 'helper' then 1 else 0 end) as helper, count(*) as total")
            ->groupBy('task_assignments.person_id')
            ->toBase()->get();

        $persons = Person::withTrashed()->whereIn('id', $rows->pluck('person_id'))->get()->keyBy('id');

        return $rows->map(fn ($row) => [
            'person' => ['id' => $row->person_id, 'name' => ($p = $persons->get($row->person_id)) ? $p->first_name.' '.$p->last_name : '–'],
            'helper' => (int) $row->helper,
            'other' => (int) $row->total - (int) $row->helper,
            'total' => (int) $row->total,
        ])->sortBy([['total', 'desc'], fn ($a, $b) => strcmp($a['person']['name'], $b['person']['name'])])->values()->all();
    }

    /**
     * One person's replies event by event (who answered when).
     *
     * @param  Collection<int, Event>  $events
     * @return list<array<string, mixed>>
     */
    public function history(Collection $events, string $personId): array
    {
        $invited = EventInvitee::query()->whereIn('event_id', $events->pluck('id'))->where('person_id', $personId)->get(['event_id', 'attended'])->keyBy('event_id');
        $responses = EventResponse::query()->whereIn('event_id', $invited->keys())->where('person_id', $personId)->get()->keyBy('event_id');
        $late = EventResponseLog::query()->whereIn('event_id', $invited->keys())->where('person_id', $personId)->where('after_deadline', true)
            ->pluck('event_id')->flip();

        return $events->filter(fn (Event $e) => $invited->has($e->id))->sortByDesc('starts_at')->map(fn (Event $e) => [
            'event' => ['id' => $e->id, 'title' => $e->title, 'type' => $e->type->value, 'starts_at' => $e->starts_at->toIso8601ZuluString()],
            'status' => $responses->get($e->id)?->status->value,
            'responded_at' => $responses->get($e->id)?->responded_at?->toIso8601ZuluString(),
            'after_deadline' => $late->has($e->id),
            'attended' => $e->type === EventType::Training
                ? ($invited[$e->id]->attended ?? $responses->get($e->id)?->status === RsvpStatus::Yes) : null,
            'attendance_recorded' => $e->attendance_recorded_at !== null,
        ])->values()->all();
    }

    /**
     * Persons who are in the team only as trainer/organizer.
     *
     * @return Collection<string, int>
     */
    private function staffOnly(Team $team): Collection
    {
        $roles = TeamMembership::query()->where('team_id', $team->id)->get(['person_id', 'role'])->groupBy('person_id');

        return $roles->filter(fn ($group) => ! $group->contains(fn ($m) => $m->role === TeamRole::Player))->keys()->flip();
    }
}
