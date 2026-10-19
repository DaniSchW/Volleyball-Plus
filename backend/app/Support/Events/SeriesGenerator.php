<?php

namespace App\Support\Events;

use App\Enums\EventStatus;
use App\Enums\EventType;
use App\Models\Event;
use App\Models\EventSeries;
use App\Models\EventTeam;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

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
     * and fills in new dates. Dates that no longer fit are cancelled (not
     * deleted, so replies stay in the statistics).
     */
    public function update(EventSeries $series): void
    {
        $tz = $this->tenants->get()->timezone;
        $weekOfFirst = CarbonImmutable::parse($series->starts_on->toDateString(), $tz)->startOfWeek();
        $ends = $series->ends_on ? CarbonImmutable::parse($series->ends_on->toDateString(), $tz)->endOfDay() : null;

        $series->events()
            ->where('starts_at', '>', Carbon::now())
            ->where('series_exception', false)
            ->each(function (Event $event) use ($series, $tz, $weekOfFirst, $ends) {
                $day = CarbonImmutable::parse($event->starts_at)->setTimezone($tz)->startOfDay();
                $fits = $this->occursOn($series, $day, $weekOfFirst) && ($ends === null || $day->lessThanOrEqualTo($ends));

                $event->fill($this->eventData($series, $day, $tz));
                $event->status = $fits ? EventStatus::Scheduled : EventStatus::Cancelled;
                $groupChanged = $event->isDirty('training_group_id');
                $event->save();
                if ($groupChanged) {
                    $this->invitees->syncEvent($event);
                }
            });

        $this->generate($series);
    }

    /** Ends the series: upcoming events are cancelled, past ones kept. */
    public function end(EventSeries $series): void
    {
        $series->events()
            ->where('starts_at', '>', Carbon::now())
            ->update(['status' => EventStatus::Cancelled->value]);

        $series->forceFill(['ends_on' => CarbonImmutable::now($this->tenants->get()->timezone)->toDateString()])->save();
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
            ->all();
    }

    private function createEvent(EventSeries $series, CarbonImmutable $day, string $tz): void
    {
        $event = Event::create($this->eventData($series, $day, $tz) + [
            'type' => EventType::Training,
            'series_id' => $series->id,
            'created_by' => $series->created_by,
        ]);
        EventTeam::create(['event_id' => $event->id, 'team_id' => $series->team_id]);
        $this->invitees->syncEvent($event);
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
            'training_group_id' => $series->training_group_id,
        ];
    }
}
