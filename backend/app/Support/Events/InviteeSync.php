<?php

namespace App\Support\Events;

use App\Enums\EventScope;
use App\Enums\EventType;
use App\Enums\TeamRole;
use App\Models\CrossNomination;
use App\Models\Event;
use App\Models\EventInvitee;
use App\Models\EventTeam;
use App\Models\TeamMembership;
use App\Models\TrainingGroupMember;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Keeps the invited persons of events in line with the team rosters:
 * - normally everybody in one of the event's teams (players, trainers,
 *   organizers),
 * - trainings of a training group: the group plus the team's trainers and
 *   organizers,
 * - team trainings also invite the team's active cross-nominated guests.
 * Roster changes update upcoming events; past events stay as they were
 * (statistics).
 */
class InviteeSync
{
    /** Sources this class maintains; others (later: manual) are left alone. */
    private const SOURCES = ['team', 'group', 'cross_nomination'];

    public function syncEvent(Event $event): void
    {
        $should = $this->shouldBeInvited($event);
        $existing = EventInvitee::query()->where('event_id', $event->id)->whereIn('source', self::SOURCES)->get()->keyBy('person_id');

        EventInvitee::query()
            ->where('event_id', $event->id)
            ->whereIn('source', self::SOURCES)
            ->whereNotIn('person_id', $should->keys())
            ->delete();

        foreach ($should as $personId => $source) {
            $invitee = $existing->get($personId);
            if ($invitee === null) {
                EventInvitee::query()->firstOrCreate(['event_id' => $event->id, 'person_id' => $personId], ['source' => $source]);
            } elseif ($invitee->source !== $source) {
                $invitee->forceFill(['source' => $source])->save();
            }
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

    /**
     * @return Collection<string, string> person id => source
     */
    private function shouldBeInvited(Event $event): Collection
    {
        $teamIds = EventTeam::query()->where('event_id', $event->id)->pluck('team_id');

        if ($event->training_group_id !== null) {
            $staff = TeamMembership::query()->whereIn('team_id', $teamIds)
                ->whereIn('role', [TeamRole::Trainer, TeamRole::Organizer])->pluck('person_id');
            $group = TrainingGroupMember::query()->where('training_group_id', $event->training_group_id)->pluck('person_id');

            return $group->mapWithKeys(fn ($id) => [$id => 'group'])
                ->merge($staff->mapWithKeys(fn ($id) => [$id => 'team']));
        }

        $team = TeamMembership::query()->whereIn('team_id', $teamIds)->distinct()->pluck('person_id')
            ->mapWithKeys(fn ($id) => [$id => 'team']);

        if ($event->type !== EventType::Training || $event->scope !== EventScope::Team) {
            return $team;
        }

        $guests = CrossNomination::query()->whereIn('host_team_id', $teamIds)->where('active', true)->pluck('person_id')
            ->mapWithKeys(fn ($id) => [$id => 'cross_nomination']);

        // Team membership wins over a guest invitation.
        return $guests->merge($team);
    }
}
