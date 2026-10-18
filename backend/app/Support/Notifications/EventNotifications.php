<?php

namespace App\Support\Notifications;

use App\Enums\NotificationCategory;
use App\Models\Event;
use App\Models\EventInvitee;
use App\Models\EventResponse;
use App\Models\Team;
use App\Models\TenantMember;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What invitees hear about events: new, changed, cancelled, reminder.
 * Past events stay silent; the person who made the change is left out.
 */
class EventNotifications
{
    /** Changes to these fields are announced; text edits are not. */
    public const RELEVANT_FIELDS = ['starts_at', 'ends_at', 'meet_at', 'location_name', 'address'];

    public function __construct(private Notifier $notifier) {}

    public function created(Event $event, ?TenantMember $actor, bool $series = false): void
    {
        $this->send($event, NotificationCategory::EventNew, $series ? 'series_new' : 'event_new', $actor);
    }

    /**
     * A schedule import: one message for the team instead of one per match.
     *
     * @param  Collection<int, Event>  $events
     */
    public function imported(Team $team, Collection $events, ?TenantMember $actor): void
    {
        $upcoming = $events->filter(fn (Event $e) => $e->ends_at->isFuture())->sortBy('starts_at');
        if ($upcoming->isEmpty()) {
            return;
        }
        $first = $upcoming->first();
        $persons = EventInvitee::query()->whereIn('event_id', $upcoming->pluck('id'))->distinct()->pluck('person_id');

        $this->notifier->toPersons(NotificationCategory::EventNew, $persons, fn (string $locale) => [
            'title' => trans_choice('notifications.imported.title', $upcoming->count(), ['count' => $upcoming->count(), 'team' => $team->name], $locale),
            'body' => __('notifications.imported.body', ['when' => $this->notifier->when($first->starts_at, $locale), 'title' => $first->title], $locale),
        ], '/teams/'.$team->id, $actor);
    }

    public function changed(Event $event, ?TenantMember $actor): void
    {
        $this->send($event, NotificationCategory::EventChanged, 'event_changed', $actor);
    }

    public function cancelled(Event $event, ?TenantMember $actor): void
    {
        $this->send($event, NotificationCategory::EventChanged, 'event_cancelled', $actor);
    }

    public function restored(Event $event, ?TenantMember $actor): void
    {
        $this->send($event, NotificationCategory::EventChanged, 'event_restored', $actor);
    }

    /** One message for the whole series, sent with its next date. */
    public function seriesChanged(Event $next, ?TenantMember $actor): void
    {
        $this->send($next, NotificationCategory::EventChanged, 'series_changed', $actor);
    }

    public function seriesEnded(Event $next, ?TenantMember $actor): void
    {
        $this->send($next, NotificationCategory::EventChanged, 'series_ended', $actor);
    }

    /** Asks everyone who has not answered yet. */
    public function remind(Event $event): int
    {
        $answered = EventResponse::query()->where('event_id', $event->id)->pluck('person_id');
        $open = $this->invitees($event)->diff($answered);

        return $this->notifier->toPersons(NotificationCategory::Reminder, $open, fn (string $locale) => [
            'title' => __('notifications.reminder.title', ['title' => $event->title], $locale),
            'body' => $event->rsvp_deadline !== null
                ? __('notifications.reminder.body_deadline', ['when' => $this->notifier->when($event->starts_at, $locale), 'deadline' => $this->notifier->when($event->rsvp_deadline, $locale)], $locale)
                : __('notifications.reminder.body', ['when' => $this->notifier->when($event->starts_at, $locale)], $locale),
        ], $this->path($event));
    }

    private function send(Event $event, NotificationCategory $category, string $kind, ?TenantMember $actor): void
    {
        if ($event->ends_at->lessThan(Carbon::now())) {
            return;
        }

        $this->notifier->toPersons($category, $this->invitees($event), fn (string $locale) => [
            'title' => __("notifications.{$kind}.title", ['title' => $event->title], $locale),
            'body' => $this->body($event, $kind, $locale),
        ], $this->path($event), $actor);
    }

    private function body(Event $event, string $kind, string $locale): string
    {
        $when = $this->notifier->when($event->starts_at, $locale);
        $place = $event->location_name ?: $event->address;

        return __("notifications.{$kind}.body", ['when' => $when], $locale).($place && $kind !== 'event_cancelled' ? ' · '.$place : '');
    }

    /**
     * @return Collection<int, string>
     */
    private function invitees(Event $event): Collection
    {
        return EventInvitee::query()->where('event_id', $event->id)->pluck('person_id');
    }

    private function path(Event $event): string
    {
        return '/events/'.$event->id;
    }
}
