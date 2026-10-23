<?php

namespace App\Support\Events;

use App\Enums\EventStatus;
use App\Enums\EventType;
use App\Models\Event;
use App\Models\EventSeries;
use App\Models\EventTeam;
use App\Models\TaskTemplate;
use App\Support\Halls\HallClosures;
use App\Support\Tasks\TaskTemplates;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Turns a weekly series into single events, a rolling window ahead
 * (default 6 months; the cron extends it daily). Times are local to the
 * department's time zone, so 19:30 stays 19:30 across daylight saving.
 */
class SeriesGenerator
{
    public const WINDOW_DAYS = 183;

    public function __construct(private InviteeSync $invitees, private TenantContext $tenants) {}

    /**
     * Creates the missing events up to the end of the window.
     *
     * @return int number of events created
     */
    public function generate(EventSeries $series, ?CarbonImmutable $today = null): int
    {
        $tz = $this->tenants->get()->timezone;
        $today ??= CarbonImmutable::now($tz)->startOfDay();
        $until = $today->addDays(self::WINDOW_DAYS);
        if ($series->ends_on !== null) {
            $until = $until->min(CarbonImmutable::parse($series->ends_on->toDateString(), $tz));
        }

        $existing = $this->existingDates($series, $tz);
        $created = 0;

        $firstDay = CarbonImmutable::parse($series->starts_on->toDateString(), $tz);
        $weekOfFirst = $firstDay->startOfWeek();

        for ($day = $firstDay; $day->lessThanOrEqualTo($until); $day = $day->addDay()) {
            if ($day->lessThan($today) || isset($existing[$day->toDateString()]) || ! $this->occursOn($series, $day, $weekOfFirst)) {
                continue;
            }

            $this->createEvent($series, $day, $tz);
            $created++;
        }

        $series->forceFill(['generated_until' => $until->toDateString()])->save();

        return $created;
    }

    /**
     * Applies changed series data to upcoming, not individually edited events
     * and fills in new dates. Dates that no longer fit (other weekdays, after
     * a new end) are cancelled – so replies stay in the statistics – or, if
     * asked for, deleted. Individually edited dates are only dropped when
     * they lie after the new end.
     *
     * @param  'cancel'|'delete'  $dropped
     */
    public function update(EventSeries $series, string $dropped = 'cancel'): void
    {
        $tz = $this->tenants->get()->timezone;

        foreach ($this->upcoming($series) as $event) {
            if (! $this->fits($series, $event, $tz)) {
                $this->drop($event, $dropped);

                continue;
            }
            if ($event->series_exception) {
                continue;
            }
            $day = CarbonImmutable::parse($event->starts_at)->setTimezone($tz)->startOfDay();
            $event->fill($this->eventData($series, $day, $tz));
            $event->status = EventStatus::Scheduled;
            $groupChanged = $event->isDirty('training_group_id');
            $event->save();
            if ($groupChanged) {
                $this->invitees->syncEvent($event);
            }
        }

        $this->generate($series);
    }

    /**
     * How many upcoming, still scheduled dates the (changed, not yet saved)
     * series would drop – to ask whether to cancel or delete them.
     */
    public function droppedCount(EventSeries $series): int
    {
        $tz = $this->tenants->get()->timezone;

        return $this->upcoming($series)
            ->filter(fn (Event $event) => $event->status === EventStatus::Scheduled && ! $this->fits($series, $event, $tz))
            ->count();
    }

    /**
     * Ends the series today: upcoming events are cancelled (or deleted),
     * past ones kept.
     *
     * @param  'cancel'|'delete'  $mode
     */
    public function end(EventSeries $series, string $mode = 'cancel'): void
    {
        foreach ($this->upcoming($series) as $event) {
            $this->drop($event, $mode);
        }

        $series->forceFill(['ends_on' => CarbonImmutable::now($this->tenants->get()->timezone)->toDateString()])->save();
    }

    /**
     * Upcoming events of the series (any status).
     *
     * @return Collection<int, Event>
     */
    private function upcoming(EventSeries $series): Collection
    {
        return $series->events()->where('starts_at', '>', Carbon::now())->orderBy('starts_at')->get();
    }

    /** Still part of the series? Individually edited dates only need to lie before the end. */
    private function fits(EventSeries $series, Event $event, string $tz): bool
    {
        $day = CarbonImmutable::parse($event->starts_at)->setTimezone($tz)->startOfDay();
        if ($series->ends_on !== null && $day->greaterThan(CarbonImmutable::parse($series->ends_on->toDateString(), $tz))) {
            return false;
        }
        if ($event->series_exception) {
            return true;
        }

        return $this->occursOn($series, $day, CarbonImmutable::parse($series->starts_on->toDateString(), $tz)->startOfWeek());
    }

    /** @param  'cancel'|'delete'  $mode */
    private function drop(Event $event, string $mode): void
    {
        if ($mode === 'delete') {
            $event->delete();
        } elseif ($event->status !== EventStatus::Cancelled) {
            $event->forceFill(['status' => EventStatus::Cancelled])->save();
        }
    }

    private function occursOn(EventSeries $series, CarbonImmutable $day, CarbonImmutable $weekOfFirst): bool
    {
        if (! in_array($day->dayOfWeekIso, array_map('intval', $series->weekdays), true)) {
            return false;
        }

        $weeks = (int) floor($weekOfFirst->diffInDays($day->startOfWeek()) / 7);

        return $weeks % max(1, $series->interval_weeks) === 0;
    }

    /**
     * Local dates that already have an event of this series (any status).
     *
     * @return array<string, true>
     */
    private function existingDates(EventSeries $series, string $tz): array
    {
        return $series->events()->pluck('starts_at')
            ->mapWithKeys(fn ($start) => [CarbonImmutable::parse($start)->setTimezone($tz)->toDateString() => true])
            ->all() + array_fill_keys($series->excluded_dates ?? [], true);
    }

    private function createEvent(EventSeries $series, CarbonImmutable $day, string $tz): void
    {
        // Hall closed that day: no date at all, or a cancelled one (left alone by later series changes).
        $closure = app(HallClosures::class)->closureFor($series->location_name, $series->address, $day);
        if ($closure?->mode === 'delete') {
            return;
        }
        $event = Event::create($this->eventData($series, $day, $tz) + [
            'type' => EventType::Training,
            'series_id' => $series->id,
            'created_by' => $series->created_by,
        ]);
        if ($closure !== null) {
            $event->forceFill(['status' => EventStatus::Cancelled, 'series_exception' => true])->save();
        }
        EventTeam::create(['event_id' => $event->id, 'team_id' => $series->team_id]);
        $this->invitees->syncEvent($event);
        if ($series->task_template_id !== null && ($template = TaskTemplate::query()->find($series->task_template_id)) !== null) {
            app(TaskTemplates::class)->apply($template, $event);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function eventData(EventSeries $series, CarbonImmutable $day, string $tz): array
    {
        $date = $day->toDateString();
        $start = CarbonImmutable::parse("{$date} {$series->start_time}", $tz);
        $end = CarbonImmutable::parse("{$date} {$series->end_time}", $tz);
        if ($end->lessThanOrEqualTo($start)) {
            $end = $end->addDay();
        }

        return [
            'title' => $series->title,
            'description' => $series->description,
            'starts_at' => $start->utc(),
            'ends_at' => $end->utc(),
            'rsvp_deadline' => $series->rsvp_deadline_minutes !== null ? $start->subMinutes($series->rsvp_deadline_minutes)->utc() : null,
            'location_name' => $series->location_name,
            'address' => $series->address,
            'max_participants' => $series->max_participants,
            'position_limits' => $series->position_limits,
            'min_participants' => $series->min_participants,
            'guests_allowed' => $series->guests_allowed,
            'guests_positions_only' => $series->guests_positions_only,
            'training_group_id' => $series->training_group_id,
        ];
    }
}
