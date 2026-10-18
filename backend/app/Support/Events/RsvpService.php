<?php

namespace App\Support\Events;

use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\EventInvitee;
use App\Models\EventResponse;
use App\Models\EventResponseLog;
use App\Models\Person;
use App\Models\Team;
use App\Models\TenantMember;
use App\Support\Auth\Permissions;
use App\Support\Teams\GuestWindow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Accept / decline / maybe. Rules:
 * - only for invited persons and events that expect replies,
 * - until the deadline (people managing the event may still change it),
 * - a reason if the team requires one (for several teams: the strictest rule),
 * - "yes" closes when the maximum number of participants is reached,
 * - cross-nominated guests may accept only from the opening time (GuestWindow).
 */
class RsvpService
{
    public function __construct(private Permissions $permissions, private GuestWindow $guests) {}

    public function respond(Event $event, Person $person, RsvpStatus $status, ?string $reason, TenantMember $actor): EventResponse
    {
        $manager = $this->permissions->canManageEvent($actor, $event);

        if (! $manager && ! $this->permissions->canRespondFor($actor, $person)) {
            throw new AccessDeniedHttpException(__('events.not_allowed'));
        }
        if (! $event->expectsResponses()) {
            throw ValidationException::withMessages(['status' => __('events.no_replies')]);
        }
        $invitee = EventInvitee::query()->where('event_id', $event->id)->where('person_id', $person->id)->first();
        if ($invitee === null) {
            throw ValidationException::withMessages(['person_id' => __('events.not_invited')]);
        }

        // Cross-nominated guests fill up places: "yes" only from the opening time.
        $guest = $invitee->source === 'cross_nomination';
        $afterDeadline = $guest ? $this->guests->isClosed($event) : $event->deadlinePassed();
        if ($afterDeadline && ! $manager) {
            throw ValidationException::withMessages(['status' => __('events.deadline_passed')]);
        }
        if ($guest && $status === RsvpStatus::Yes && ! $manager && ! $this->guests->isOpen($event)) {
            throw ValidationException::withMessages(['status' => __('events.guests_not_open', [
                'at' => $this->guests->opensAt($event)->copy()->setTimezone($event->tenant->timezone)->locale(app()->getLocale())->isoFormat(__('notifications.date_format')),
            ])]);
        }

        $reason = trim((string) $reason) ?: null;
        if ($reason === null && $this->reasonRequired($event, $status)) {
            throw ValidationException::withMessages(['reason' => __('events.reason_required')]);
        }

        return DB::transaction(function () use ($event, $person, $status, $reason, $actor, $afterDeadline) {
            // Lock the event so the participant limit holds with simultaneous replies.
            $locked = Event::query()->lockForUpdate()->findOrFail($event->id);
            $current = EventResponse::query()->where('event_id', $event->id)->where('person_id', $person->id)->first();

            if ($status === RsvpStatus::Yes && $current?->status !== RsvpStatus::Yes && $locked->max_participants !== null) {
                $yes = EventResponse::query()->where('event_id', $event->id)->where('status', RsvpStatus::Yes)->count();
                if ($yes >= $locked->max_participants) {
                    throw ValidationException::withMessages(['status' => __('events.full')]);
                }
            }

            $response = $current ?? new EventResponse(['event_id' => $event->id, 'person_id' => $person->id]);
            $old = $current?->status;
            $response->fill([
                'status' => $status,
                'reason' => $status === RsvpStatus::Yes ? null : $reason,
                'responded_by' => $actor->id,
                'responded_at' => Carbon::now(),
            ])->save();

            EventResponseLog::create([
                'event_id' => $event->id,
                'person_id' => $person->id,
                'old_status' => $old?->value,
                'new_status' => $status->value,
                'responded_by' => $actor->id,
                'after_deadline' => $afterDeadline,
            ]);

            return $response;
        });
    }

    public function reasonRequired(Event $event, RsvpStatus $status): bool
    {
        return match ($status) {
            RsvpStatus::No => $event->teams->contains(fn (Team $t) => $t->decline_reason_required),
            RsvpStatus::Maybe => $event->teams->contains(fn (Team $t) => $t->maybe_reason_required),
            RsvpStatus::Yes => false,
        };
    }
}
