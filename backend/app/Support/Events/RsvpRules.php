<?php

namespace App\Support\Events;

use App\Models\Event;
use App\Models\EventInvitee;
use App\Models\EventResponse;
use App\Models\Person;
use App\Models\RsvpRule;
use App\Models\TenantMember;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Standing replies ("Daueranwort"): a person answers once for all matching
 * events – e.g. "Wednesday trainings yes, Friday trainings no". Applied
 * right away to the upcoming events the person is invited to (whose reply
 * time is still open) and later to every new matching event (series
 * extended by the cron, extra trainings). Each event can still be answered
 * on its own; the rule never overwrites an answer of a later event.
 */
class RsvpRules
{
    public function __construct(private RsvpService $rsvp, private TenantContext $tenants) {}

    /**
     * Saves the rule and answers the upcoming events.
     *
     * @return array{applied: int, skipped: list<array{event: array<string, mixed>, message: string}>}
     */
    public function create(RsvpRule $rule, TenantMember $actor, bool $overwrite): array
    {
        $rule->save();
        $person = Person::query()->findOrFail($rule->person_id);

        $events = Event::query()->with('teams')
            ->whereIn('id', EventInvitee::query()->where('person_id', $person->id)->select('event_id'))
            ->where('starts_at', '>', Carbon::now())
            ->orderBy('starts_at')
            ->get()
            ->filter(fn (Event $event) => $event->expectsResponses() && $this->matches($rule, $event));

        $answered = EventResponse::query()->where('person_id', $person->id)->whereIn('event_id', $events->pluck('id'))->pluck('status', 'event_id');
        $applied = 0;
        $skipped = [];
        foreach ($events as $event) {
            if ($answered->has($event->id) && (! $overwrite || $answered[$event->id] === $rule->status)) {
                continue;
            }
            $error = $this->answer($event, $person, $rule, $actor);
            if ($error === null) {
                $applied++;
            } else {
                $skipped[] = ['event' => ['id' => $event->id, 'title' => $event->title, 'starts_at' => $event->starts_at->toIso8601ZuluString()], 'message' => $error];
            }
        }

        return ['applied' => $applied, 'skipped' => $skipped];
    }

    /**
     * New invitations (new event, roster change): answer them by the
     * persons' rules, unless already answered. Failures are silent – the
     * person can still answer by hand.
     *
     * @param  Collection<int, string>|list<string>  $personIds
     */
    public function applyToNewInvitees(Event $event, iterable $personIds): void
    {
        $personIds = collect($personIds)->values();
        if ($personIds->isEmpty() || ! $event->expectsResponses() || $event->starts_at->lessThanOrEqualTo(Carbon::now())) {
            return;
        }
        $rules = RsvpRule::query()->whereIn('person_id', $personIds)->orderByDesc('created_at')->get();
        if ($rules->isEmpty()) {
            return;
        }
        $event->load('teams');
        $answered = EventResponse::query()->where('event_id', $event->id)->whereIn('person_id', $personIds)->pluck('person_id')->flip();

        foreach ($rules->groupBy('person_id') as $personId => $own) {
            // The newest matching rule wins.
            $rule = $own->first(fn (RsvpRule $r) => $this->matches($r, $event));
            if ($rule === null || $answered->has($personId)) {
                continue;
            }
            $actor = TenantMember::query()->find($rule->created_by);
            $person = Person::query()->find($personId);
            if ($actor !== null && $person !== null) {
                $this->answer($event, $person, $rule, $actor);
            }
        }
    }

    public function matches(RsvpRule $rule, Event $event): bool
    {
        $day = $event->starts_at->copy()->setTimezone($this->tenants->get()->timezone);
        if ($rule->valid_until !== null && $day->toDateString() > $rule->valid_until->toDateString()) {
            return false;
        }
        if ($rule->team_id !== null && ! $event->teams->contains('id', $rule->team_id)) {
            return false;
        }
        if ($rule->series_id !== null && $event->series_id !== $rule->series_id) {
            return false;
        }
        if ($rule->event_type !== null && $event->type !== $rule->event_type) {
            return false;
        }
        if (! empty($rule->weekdays)) {
            return in_array($day->dayOfWeekIso, array_map('intval', $rule->weekdays), true);
        }

        return true;
    }

    /** Null if answered, else the reason why not (full, deadline passed, …). */
    private function answer(Event $event, Person $person, RsvpRule $rule, TenantMember $actor): ?string
    {
        try {
            $this->rsvp->respond($event, $person, $rule->status, $rule->reason, $actor, null, viaRule: true);

            return null;
        } catch (ValidationException $e) {
            return collect($e->errors())->flatten()->first() ?? $e->getMessage();
        } catch (HttpException $e) {
            return $e->getMessage();
        }
    }
}
