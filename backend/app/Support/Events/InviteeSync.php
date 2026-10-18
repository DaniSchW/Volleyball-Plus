<?php

namespace App\Support\Events;

use App\Models\Event;
use App\Models\EventInvitee;
use App\Models\EventTeam;
use App\Models\TeamMembership;
use Illuminate\Support\Carbon;

/**
 * Keeps the invited persons of events in line with the team rosters:
 * everybody in one of the event's teams (players, trainers, organizers) is
 * invited. Roster changes update upcoming events; past events stay as they
 * were (statistics).
 */
class InviteeSync
{
    public function syncEvent(Event $event): void
    {
        $teamIds = EventTeam::query()->where('event_id', $event->id)->pluck('team_id');
        $should = TeamMembership::query()->whereIn('team_id', $teamIds)->distinct()->pluck('person_id');

        $existing = EventInvitee::query()->where('event_id', $event->id)->where('source', 'team')->pluck('person_id');

        EventInvitee::query()
            ->where('event_id', $event->id)
            ->where('source', 'team')
            ->whereNotIn('person_id', $should)
            ->delete();

        foreach ($should->diff($existing) as $personId) {
            EventInvitee::query()->firstOrCreate(['event_id' => $event->id, 'person_id' => $personId], ['source' => 'team']);
        }
    }

    /** After a roster change: all upcoming events of the team. */
    public function syncTeam(string $teamId): void
    {
        $eventIds = EventTeam::query()->where('team_id', $teamId)->pluck('event_id');

        Event::query()
            ->whereIn('id', $eventIds)
            ->where('ends_at', '>=', Carbon::now())
            ->each(fn (Event $event) => $this->syncEvent($event));
    }
}
