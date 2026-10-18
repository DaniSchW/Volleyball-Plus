<?php

namespace App\Support\Teams;

use App\Enums\CrossNominationMode;
use App\Models\Event;
use App\Models\Team;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * When cross-nominated guests may accept a training: from the event's own
 * setting, else the host team's default. Guests fill up free places, so if
 * they may only start at or after the reply deadline, they can reply until
 * the training begins.
 */
class GuestWindow
{
    public function opensAt(Event $event): ?CarbonInterface
    {
        if ($event->cross_nomination_opens_at !== null) {
            return $event->cross_nomination_opens_at;
        }

        /** @var Team|null $host */
        $host = $event->teams->first();

        return match ($host?->cross_nomination_mode) {
            CrossNominationMode::HoursBeforeStart => $event->starts_at->copy()->subHours($host->cross_nomination_hours ?? 24),
            CrossNominationMode::AfterRsvpDeadline => $event->rsvp_deadline,
            default => null,
        };
    }

    public function closesAt(Event $event): CarbonInterface
    {
        $opens = $this->opensAt($event);
        $deadline = $event->rsvp_deadline;

        if ($deadline === null || ($opens !== null && $opens->greaterThanOrEqualTo($deadline))) {
            return $event->starts_at;
        }

        return $deadline;
    }

    public function isOpen(Event $event, ?CarbonInterface $now = null): bool
    {
        $now ??= Carbon::now();
        $opens = $this->opensAt($event);

        return $opens === null || $opens->lessThanOrEqualTo($now);
    }

    public function isClosed(Event $event, ?CarbonInterface $now = null): bool
    {
        return $this->closesAt($event)->lessThanOrEqualTo($now ?? Carbon::now());
    }
}
