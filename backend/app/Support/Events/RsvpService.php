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
 * - with a Positions-Soll, "yes" counts for the player's main position, or
 *   the first secondary one with a free place (or the chosen position),
 * - cross-nominated guests may accept only if the event allows guests
 *   (GuestWindow); with "only missing positions" only for a position of the
 *   Positions-Soll (else the team's ideal squad) that still has a free place.
 */
class RsvpService
{
    public function __construct(private Permissions $permissions, private GuestWindow $guests) {}

    public function respond(Event $event, Person $person, RsvpStatus $status, ?string $reason, TenantMember $actor, ?string $position = null): EventResponse
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
            throw ValidationException::withMessages(['status' => __('events.guests_not_allowed')]);
        }

        $reason = trim((string) $reason) ?: null;
        if ($reason === null && $this->reasonRequired($event, $status)) {
            throw ValidationException::withMessages(['reason' => __('events.reason_required')]);
        }

        $own = array_values(array_unique(array_filter([$person->primary_position?->value, ...($person->secondary_positions ?? [])])));
        if ($position !== null && ! in_array($position, $own, true) && ! $manager) {
            throw ValidationException::withMessages(['position' => __('events.position_not_yours')]);
        }

        $neededOnly = $guest && ! $manager && $event->guests_positions_only;

        return DB::transaction(function () use ($event, $person, $status, $reason, $actor, $afterDeadline, $own, $position, $neededOnly) {
            // Lock the event so the participant limit holds with simultaneous replies.
            $locked = Event::query()->lockForUpdate()->findOrFail($event->id);
            $current = EventResponse::query()->where('event_id', $event->id)->where('person_id', $person->id)->first();

            if ($status === RsvpStatus::Yes && $current?->status !== RsvpStatus::Yes && $locked->max_participants !== null) {
                $yes = EventResponse::query()->where('event_id', $event->id)->where('status', RsvpStatus::Yes)->count();
                if ($yes >= $locked->max_participants) {
                    throw ValidationException::withMessages(['status' => __('events.full')]);
                }
            }

            $asPosition = $status === RsvpStatus::Yes ? $this->positionFor($locked, $person, $own, $position, $current, $neededOnly) : null;

            $response = $current ?? new EventResponse(['event_id' => $event->id, 'person_id' => $person->id]);
            $old = $current?->status;
            $response->fill([
                'status' => $status,
                'as_position' => $asPosition,
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

    /**
     * The position the "yes" counts for. Without limits simply the chosen or
     * main position; with limits the first one that still has a place.
     * $neededOnly (guests): only positions still missing by the Positions-Soll
     * or, without one, by the team's ideal squad.
     *
     * @param  list<string>  $own
     */
    private function positionFor(Event $event, Person $person, array $own, ?string $chosen, ?EventResponse $current, bool $neededOnly = false): ?string
    {
        $limits = $neededOnly ? $event->guestPositionNeeds() : ($event->position_limits ?? []);
        $candidates = $chosen !== null ? [$chosen] : $own;
        // Accepting again keeps the place one already has.
        if ($chosen === null && $current?->status === RsvpStatus::Yes && $current->as_position !== null) {
            $candidates = array_values(array_unique([$current->as_position, ...$candidates]));
        }
        if ($neededOnly) {
            $candidates = array_values(array_filter($candidates, fn (string $c) => isset($limits[$c])));
            if ($candidates === []) {
                throw ValidationException::withMessages(['status' => __('events.guest_position_not_needed')]);
            }
        }
        if ($limits === [] || $candidates === []) {
            return $candidates[0] ?? null;
        }

        $taken = EventResponse::query()->where('event_id', $event->id)->where('status', RsvpStatus::Yes)
            ->where('person_id', '!=', $person->id)->whereNotNull('as_position')
            ->selectRaw('as_position, count(*) as n')->groupBy('as_position')->toBase()->pluck('n', 'as_position');

        foreach ($candidates as $candidate) {
            if (! isset($limits[$candidate]) || (int) ($taken[$candidate] ?? 0) < $limits[$candidate]) {
                return $candidate;
            }
        }

        throw ValidationException::withMessages(['status' => __('events.position_full', [
            'positions' => implode(', ', array_map(fn (string $p) => __('events.positions.'.$p), $candidates)),
        ])]);
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
