<?php

namespace App\Support\Teams;

use App\Models\Event;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Cross-nominated guests either may accept a training or not (checkbox per
 * training). If they may, the same deadline applies as for everybody.
 */
class GuestWindow
{
    /** Null: guests may accept (now); otherwise never. */
    public function opensAt(Event $event): ?CarbonInterface
    {
        return null;
    }

    public function closesAt(Event $event): CarbonInterface
    {
        return $event->rsvp_deadline ?? $event->starts_at;
    }

    public function isOpen(Event $event, ?CarbonInterface $now = null): bool
    {
        return $event->guests_allowed !== false;
    }

    public function isClosed(Event $event, ?CarbonInterface $now = null): bool
    {
        return $this->closesAt($event)->lessThanOrEqualTo($now ?? Carbon::now());
    }
}
