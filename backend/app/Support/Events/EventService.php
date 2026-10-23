<?php

namespace App\Support\Events;

use App\Enums\EventScope;
use App\Enums\EventStatus;
use App\Enums\EventType;
use App\Models\Event;
use App\Models\EventSeries;
use App\Models\EventTeam;
use App\Models\Opponent;
use App\Models\Team;
use App\Models\TenantMember;
use App\Support\Notifications\EventNotifications;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Creating and changing events. Input times are local to the department
 * (as entered), stored in UTC.
 */
class EventService
{
    public const EVENT_FIELDS = [
        'type', 'response_mode', 'title', 'description', 'starts_at', 'ends_at', 'meet_at', 'rsvp_deadline',
        'location_name', 'address', 'max_participants', 'min_participants', 'guests_allowed', 'guests_positions_only', 'position_limits', 'opponent_id', 'is_home', 'cross_nomination_opens_at', 'training_group_id',
    ];

    public function __construct(
        private InviteeSync $invitees,
        private SeriesGenerator $series,
        private TenantContext $tenants,
        private EventNotifications $notifications,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated input
     * @param  Collection<int, Team>  $teams
     * @param  array{weekdays: list<int>, interval_weeks?: int, ends_on?: ?string}|null  $repeat
     */
    public function create(array $data, Collection $teams, EventScope $scope, TenantMember $creator, ?array $repeat): Event
    {
        $event = DB::transaction(function () use ($data, $teams, $scope, $creator, $repeat) {
            $attributes = $this->attributes($data);

            if ($repeat !== null) {
                return $this->createSeries($attributes, $teams->first(), $creator, $repeat);
            }

            $event = Event::create($attributes + ['scope' => $scope, 'created_by' => $creator->id]);
            foreach ($teams as $team) {
                EventTeam::create(['event_id' => $event->id, 'team_id' => $team->id]);
            }
            $this->invitees->syncEvent($event);

            return $event;
        });

        // A new series is announced once, not with every date.
        $this->notifications->created($event, $creator, series: $repeat !== null);

        return $event;
    }

    /**
     * Changes a single event. Within a series it becomes an exception, so
     * later changes to the series leave it alone.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Event $event, array $data, ?TenantMember $actor = null): Event
    {
        $event->fill($this->attributes($data, $event));
        if ($event->series_id !== null && $event->isDirty()) {
            $event->series_exception = true;
        }
        $relevant = $event->isDirty(EventNotifications::RELEVANT_FIELDS);
        $groupChanged = $event->isDirty('training_group_id');
        $event->save();

        if ($groupChanged) {
            $this->invitees->syncEvent($event);
        }

        if ($relevant && ! $event->isCancelled()) {
            $this->notifications->changed($event, $actor);
        }

        return $event;
    }

    /**
     * Changes the whole series from now on (title, times, place, weekdays …).
     * Dates that drop out (shorter series, other weekdays) are cancelled or
     * deleted, as chosen.
     *
     * @param  array<string, mixed>  $data
     * @param  array{weekdays: list<int>, interval_weeks?: int, ends_on?: ?string}  $repeat
     * @param  'cancel'|'delete'  $dropped
     */
    public function updateSeries(EventSeries $series, array $data, array $repeat, ?TenantMember $actor = null, string $dropped = 'cancel'): EventSeries
    {
        $this->fillSeries($series, $data, $repeat)->save();

        DB::transaction(fn () => $this->series->update($series, $dropped));

        $next = $this->nextOf($series);
        if ($next !== null) {
            $this->notifications->seriesChanged($next, $actor);
        }

        return $series;
    }

    /**
     * How many upcoming dates the change would drop – nothing is saved.
     *
     * @param  array<string, mixed>  $data
     * @param  array{weekdays: list<int>, interval_weeks?: int, ends_on?: ?string}  $repeat
     */
    public function seriesDropCount(EventSeries $series, array $data, array $repeat): int
    {
        return $this->series->droppedCount($this->fillSeries(clone $series, $data, $repeat));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{weekdays: list<int>, interval_weeks?: int, ends_on?: ?string}  $repeat
     */
    private function fillSeries(EventSeries $series, array $data, array $repeat): EventSeries
    {
        $attributes = $this->attributes($data);
        $tz = $this->tenants->get()->timezone;
        $start = CarbonImmutable::parse($attributes['starts_at'])->setTimezone($tz);
        $end = CarbonImmutable::parse($attributes['ends_at'])->setTimezone($tz);

        return $series->fill([
            'title' => $attributes['title'],
            'description' => $attributes['description'] ?? null,
            'weekdays' => array_values(array_unique(array_map('intval', $repeat['weekdays']))),
            'interval_weeks' => $repeat['interval_weeks'] ?? 1,
            'ends_on' => $repeat['ends_on'] ?? null,
            'start_time' => $start->format('H:i:s'),
            'end_time' => $end->format('H:i:s'),
            'location_name' => $attributes['location_name'] ?? null,
            'address' => $attributes['address'] ?? null,
            'rsvp_deadline_minutes' => isset($attributes['rsvp_deadline'])
                ? (int) CarbonImmutable::parse($attributes['rsvp_deadline'])->diffInMinutes($start, true) : null,
            'max_participants' => $attributes['max_participants'] ?? null,
            'position_limits' => $attributes['position_limits'] ?? null,
            'min_participants' => $attributes['min_participants'] ?? null,
            'guests_allowed' => $attributes['guests_allowed'] ?? true,
            'guests_positions_only' => $attributes['guests_positions_only'] ?? false,
            'training_group_id' => $attributes['training_group_id'] ?? null,
        ]);
    }

    /**
     * Ends a training series; upcoming dates are cancelled or deleted.
     *
     * @param  'cancel'|'delete'  $mode
     */
    public function endSeries(EventSeries $series, ?TenantMember $actor = null, string $mode = 'cancel'): void
    {
        $next = $this->nextOf($series);
        if ($next !== null) {
            // Announced before the dates (and with delete the event itself) are gone.
            $this->notifications->seriesEnded($next, $actor, linkEvent: $mode !== 'delete');
        }
        DB::transaction(fn () => $this->series->end($series, $mode));
    }

    /**
     * Removes an event for good, with its replies and tasks. An upcoming
     * event is announced as cancelled first; a series date is remembered so
     * the series does not create it again.
     */
    /**
     * $remember false: the series may create the date again later (a hall
     * closure keeps it away only while the closure lasts).
     */
    public function delete(Event $event, ?TenantMember $actor = null, bool $notify = true, bool $remember = true): void
    {
        if ($notify && ! $event->isCancelled()) {
            $this->notifications->deleted($event, $actor);
        }

        DB::transaction(function () use ($event, $remember) {
            if ($remember && $event->series_id !== null && ($series = $event->series) !== null) {
                $day = CarbonImmutable::parse($event->starts_at)->setTimezone($this->tenants->get()->timezone)->toDateString();
                $series->forceFill(['excluded_dates' => array_values(array_unique([...($series->excluded_dates ?? []), $day]))])->save();
            }
            $event->delete();
        });
    }

    /** $notify false: the caller sends one message for several events (e.g. a hall closure). */
    public function cancel(Event $event, ?TenantMember $actor = null, bool $notify = true): void
    {
        $wasCancelled = $event->isCancelled();
        $event->forceFill([
            'status' => EventStatus::Cancelled,
            'series_exception' => $event->series_id !== null ? true : $event->series_exception,
        ])->save();

        if ($notify && ! $wasCancelled) {
            $this->notifications->cancelled($event, $actor);
        }
    }

    public function restore(Event $event, ?TenantMember $actor = null): void
    {
        $wasCancelled = $event->isCancelled();
        $event->forceFill(['status' => EventStatus::Scheduled])->save();

        if ($wasCancelled) {
            $this->notifications->restored($event, $actor);
        }
    }

    private function nextOf(EventSeries $series): ?Event
    {
        return $series->events()
            ->where('starts_at', '>', Carbon::now())
            ->where('status', EventStatus::Scheduled)
            ->orderBy('starts_at')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array{weekdays: list<int>, interval_weeks?: int, ends_on?: ?string}  $repeat
     */
    private function createSeries(array $attributes, Team $team, TenantMember $creator, array $repeat): Event
    {
        $tz = $this->tenants->get()->timezone;
        $start = CarbonImmutable::parse($attributes['starts_at'])->setTimezone($tz);
        $end = CarbonImmutable::parse($attributes['ends_at'])->setTimezone($tz);

        $series = EventSeries::create([
            'team_id' => $team->id,
            'title' => $attributes['title'],
            'description' => $attributes['description'] ?? null,
            'weekdays' => array_values(array_unique(array_map('intval', $repeat['weekdays']))),
            'interval_weeks' => $repeat['interval_weeks'] ?? 1,
            'starts_on' => $start->toDateString(),
            'ends_on' => $repeat['ends_on'] ?? null,
            'start_time' => $start->format('H:i:s'),
            'end_time' => $end->format('H:i:s'),
            'location_name' => $attributes['location_name'] ?? null,
            'address' => $attributes['address'] ?? null,
            'rsvp_deadline_minutes' => isset($attributes['rsvp_deadline'])
                ? (int) CarbonImmutable::parse($attributes['rsvp_deadline'])->diffInMinutes($start, true) : null,
            'max_participants' => $attributes['max_participants'] ?? null,
            'position_limits' => $attributes['position_limits'] ?? null,
            'min_participants' => $attributes['min_participants'] ?? null,
            'guests_allowed' => $attributes['guests_allowed'] ?? true,
            'guests_positions_only' => $attributes['guests_positions_only'] ?? false,
            'training_group_id' => $attributes['training_group_id'] ?? null,
            'created_by' => $creator->id,
        ]);

        $this->series->generate($series, $start->startOfDay());

        return $series->events()->orderBy('starts_at')->firstOrFail();
    }

    /**
     * Normalises input: local times → UTC, default titles.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, ?Event $existing = null): array
    {
        $tz = $this->tenants->get()->timezone;
        $attributes = Arr::only($data, self::EVENT_FIELDS);

        foreach (['starts_at', 'ends_at', 'meet_at', 'rsvp_deadline', 'cross_nomination_opens_at'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $attributes[$field] = $attributes[$field] === null ? null : CarbonImmutable::parse($attributes[$field], $tz)->utc();
            }
        }

        $type = EventType::tryFrom((string) ($attributes['type'] ?? $existing?->type?->value));
        // Helper services take part via their tasks, not via accept/decline.
        if ($type === EventType::Helper) {
            $attributes['response_mode'] = 'info';
        } elseif ($type !== EventType::Extra) {
            $attributes['response_mode'] = 'rsvp';
        }
        if (array_key_exists('position_limits', $attributes)) {
            $limits = array_filter((array) $attributes['position_limits'], fn ($n) => $n !== null && $n !== '');
            $attributes['position_limits'] = $limits === [] ? null : array_map('intval', $limits);
        }
        // Guests and training groups only exist for trainings.
        if ($type !== EventType::Training) {
            $attributes['cross_nomination_opens_at'] = null;
            $attributes['training_group_id'] = null;
            $attributes['min_participants'] = null;
        }
        if (array_key_exists('title', $attributes) || $existing === null) {
            $opponent = $type === EventType::Match && ! empty($attributes['opponent_id'] ?? $existing?->opponent_id)
                ? Opponent::query()->find($attributes['opponent_id'] ?? $existing?->opponent_id)?->name
                : null;
            $attributes['title'] = trim((string) ($attributes['title'] ?? ''))
                ?: ($opponent ? __('events.default_title.match_vs', ['opponent' => $opponent]) : __('events.default_title.'.($type?->value ?? 'extra')));
        }

        return $attributes;
    }
}
