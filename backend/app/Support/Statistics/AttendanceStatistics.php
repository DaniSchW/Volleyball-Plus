<?php

namespace App\Support\Statistics;

use App\Enums\EventStatus;
use App\Enums\ResponseMode;
use App\Enums\TeamRole;
use App\Models\Event;
use App\Models\EventInvitee;
use App\Models\EventResponse;
use App\Models\EventResponseLog;
use App\Models\Person;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Reply rates of a team's people in a period: for every past event of the
 * team that took replies (not cancelled) and to which the person was
 * invited – accepted, maybe, declined, no answer. Rate = accepted /
 * invited. Answers given or changed after the deadline are counted too.
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
     * current season (from the 1st of the season's start month).
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
            'season' => ['from' => $season->toDateString(), 'to' => $today->toDateString()],
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
            ->get(['event_id', 'person_id', 'source']);

        $staffOnly = $this->staffOnly($team);
        $invitees = $invitees->reject(fn (EventInvitee $i) => $staffOnly->has($i->person_id));

        $responses = EventResponse::query()->whereIn('event_id', $eventIds)->whereIn('person_id', $invitees->pluck('person_id')->unique())
            ->get(['event_id', 'person_id', 'status'])->keyBy(fn ($r) => $r->event_id.'|'.$r->person_id);
        $late = EventResponseLog::query()->whereIn('event_id', $eventIds)->where('after_deadline', true)
            ->selectRaw('person_id, count(distinct event_id) as n')->groupBy('person_id')->toBase()->pluck('n', 'person_id');

        $persons = Person::withTrashed()->whereIn('id', $invitees->pluck('person_id')->unique())->get()->keyBy('id');
        $current = TeamMembership::query()->where('team_id', $team->id)->where('role', TeamRole::Player)->pluck('person_id')->flip();

        return $invitees->groupBy('person_id')->map(function (Collection $rows, string $personId) use ($responses, $late, $persons, $current) {
            $counts = ['yes' => 0, 'maybe' => 0, 'no' => 0, 'open' => 0];
            foreach ($rows as $invite) {
                $status = $responses->get($invite->event_id.'|'.$personId)?->status->value ?? 'open';
                $counts[$status]++;
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
            ];
        })
            ->sortBy([['rate', 'desc'], ['invited', 'desc'], fn ($a, $b) => strcmp(mb_strtolower($a['person']['name']), mb_strtolower($b['person']['name']))])
            ->values()->all();
    }

    /**
     * One person's replies event by event (who answered when).
     *
     * @param  Collection<int, Event>  $events
     * @return list<array<string, mixed>>
     */
    public function history(Collection $events, string $personId): array
    {
        $invited = EventInvitee::query()->whereIn('event_id', $events->pluck('id'))->where('person_id', $personId)->pluck('event_id')->flip();
        $responses = EventResponse::query()->whereIn('event_id', $invited->keys())->where('person_id', $personId)->get()->keyBy('event_id');
        $late = EventResponseLog::query()->whereIn('event_id', $invited->keys())->where('person_id', $personId)->where('after_deadline', true)
            ->pluck('event_id')->flip();

        return $events->filter(fn (Event $e) => $invited->has($e->id))->sortByDesc('starts_at')->map(fn (Event $e) => [
            'event' => ['id' => $e->id, 'title' => $e->title, 'type' => $e->type->value, 'starts_at' => $e->starts_at->toIso8601ZuluString()],
            'status' => $responses->get($e->id)?->status->value,
            'responded_at' => $responses->get($e->id)?->responded_at?->toIso8601ZuluString(),
            'after_deadline' => $late->has($e->id),
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
