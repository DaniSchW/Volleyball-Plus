<?php

namespace App\Support\Halls;

use App\Enums\EventStatus;
use App\Enums\NotificationCategory;
use App\Models\Event;
use App\Models\EventInvitee;
use App\Models\Hall;
use App\Models\HallClosure;
use App\Models\TenantMember;
use App\Support\Events\EventService;
use App\Support\Notifications\Notifier;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Hall closures (holidays, tournament, repairs). Events have no hall
 * reference – they take over the hall's name and address as their place –
 * so an event lies in a hall when its place is the hall's name or address.
 */
class HallClosures
{
    public function __construct(private TenantContext $tenants, private EventService $events, private Notifier $notifier) {}

    /**
     * Events in the hall within the period (any type and status), oldest first.
     *
     * @return Collection<int, Event>
     */
    public function affected(Hall $hall, string $from, string $until): Collection
    {
        [$start, $end] = $this->bounds($from, $until);

        return $this->inHall(Event::query(), $hall)
            ->where('starts_at', '>=', $start)->where('starts_at', '<=', $end)
            ->with('teams')->orderBy('starts_at')->get();
    }

    /**
     * Closes the hall and cancels or deletes the chosen events (only those
     * really affected). The invitees of upcoming events get one message
     * for the whole closure instead of one per event.
     *
     * @param  list<string>  $eventIds
     */
    public function close(Hall $hall, array $data, array $eventIds, ?TenantMember $actor): HallClosure
    {
        $closure = HallClosure::create(['hall_id' => $hall->id] + $data);
        $chosen = $this->affected($hall, $data['starts_on'], $data['ends_on'])->whereIn('id', $eventIds);
        $upcoming = $chosen->filter(fn (Event $e) => $e->ends_at->isFuture() && $e->status === EventStatus::Scheduled);
        $persons = EventInvitee::query()->whereIn('event_id', $upcoming->pluck('id'))->distinct()->pluck('person_id');

        DB::transaction(function () use ($chosen, $closure, $actor) {
            foreach ($chosen as $event) {
                $closure->mode === 'delete'
                    ? $this->events->delete($event, $actor, notify: false, remember: false)
                    : $this->events->cancel($event, $actor, notify: false);
            }
        });

        if ($upcoming->isNotEmpty()) {
            $this->notifier->toPersons(NotificationCategory::EventChanged, $persons, fn (string $locale) => [
                'title' => __('notifications.hall_closed.title', ['hall' => $hall->name], $locale),
                'body' => __('notifications.hall_closed.body', [
                    'from' => $closure->starts_on->locale($locale)->isoFormat('l'),
                    'until' => $closure->ends_on->locale($locale)->isoFormat('l'),
                ], $locale).($closure->reason ? ' ('.$closure->reason.')' : ''),
            ], '', $actor);
        }

        return $closure;
    }

    /**
     * The closure that covers this place and local day, if any – used when a
     * series creates its dates.
     */
    public function closureFor(?string $locationName, ?string $address, CarbonImmutable $day): ?HallClosure
    {
        if (blank($locationName) && blank($address)) {
            return null;
        }
        $date = $day->toDateString();

        return HallClosure::query()
            ->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)
            ->whereHas('hall', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->when(filled($locationName), fn (Builder $x) => $x->orWhere('name', $locationName))
                ->when(filled($address), fn (Builder $x) => $x->orWhere('address', $address))))
            ->orderByDesc('created_at')->first();
    }

    /**
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    private function inHall(Builder $query, Hall $hall): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('location_name', $hall->name)
            ->when($hall->address !== null, fn (Builder $w) => $w->orWhere('address', $hall->address)));
    }

    /** @return array{0: Carbon, 1: Carbon} local whole days as UTC */
    private function bounds(string $from, string $until): array
    {
        $tz = $this->tenants->get()->timezone;

        return [
            Carbon::parse($from, $tz)->startOfDay()->utc(),
            Carbon::parse($until, $tz)->endOfDay()->utc(),
        ];
    }
}
