<?php

namespace App\Support\Halls;

use App\Models\Hall;
use App\Models\HallBooking;
use App\Models\HallSlot;
use Illuminate\Support\Collection;

/**
 * The department's hall plan. Two bookings conflict when they use the same
 * hall on the same weekday at overlapping times and validity periods, and
 * a shared part of the hall (no parts = whole hall). Conflicts only warn.
 * Hall times and bookings concern trainings; match days and other events
 * are not checked against them.
 */
class HallPlan
{
    /** @return list<array<string, mixed>> */
    public function present(): array
    {
        $halls = Hall::query()->with(['slots', 'bookings.team'])->orderBy('name')->get();

        return $halls->map(fn (Hall $hall) => $this->presentHall($hall))->values()->all();
    }

    /** @return array<string, mixed> */
    public function presentHall(Hall $hall): array
    {
        $bookings = $hall->bookings->filter(fn (HallBooking $b) => $b->team !== null);

        return [
            'id' => $hall->id,
            'name' => $hall->name,
            'address' => $hall->address,
            'street' => $hall->street,
            'house_number' => $hall->house_number,
            'postal_code' => $hall->postal_code,
            'city' => $hall->city,
            'courts' => $hall->courts,
            'contact' => $hall->contact_name || $hall->contact_phone || $hall->contact_email
                ? ['name' => $hall->contact_name, 'phone' => $hall->contact_phone, 'email' => $hall->contact_email] : null,
            'notes' => $hall->notes,
            'slots' => $hall->slots->map(fn (HallSlot $slot) => [
                'id' => $slot->id,
                'weekday' => $slot->weekday,
                'start_time' => self::hm($slot->start_time),
                'end_time' => self::hm($slot->end_time),
                'courts' => $slot->courtList(),
                'valid_from' => $slot->valid_from?->toDateString(),
                'valid_until' => $slot->valid_until?->toDateString(),
            ])->values()->all(),
            'bookings' => $bookings->map(fn (HallBooking $b) => $this->presentBooking($b, $hall, $bookings))->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, HallBooking>  $others
     * @return array<string, mixed>
     */
    private function presentBooking(HallBooking $booking, Hall $hall, Collection $others): array
    {
        return [
            'id' => $booking->id,
            'team' => ['id' => $booking->team->id, 'name' => $booking->team->name, 'color' => $booking->team->color, 'archived' => $booking->team->archived_at !== null],
            'weekday' => $booking->weekday,
            'start_time' => self::hm($booking->start_time),
            'end_time' => self::hm($booking->end_time),
            'courts' => $booking->courtList(),
            'valid_from' => $booking->valid_from?->toDateString(),
            'valid_until' => $booking->valid_until?->toDateString(),
            'notes' => $booking->notes,
            'conflicts' => $this->conflicts($booking, $others)->map(fn (HallBooking $o) => [
                'id' => $o->id, 'team' => $o->team->name, 'start_time' => self::hm($o->start_time), 'end_time' => self::hm($o->end_time), 'courts' => $o->courtList(),
            ])->values()->all(),
            'outside_slots' => ! $this->withinSlots($booking, $hall->slots),
        ];
    }

    /**
     * Other bookings of the same hall that overlap (archived teams ignored).
     *
     * @param  Collection<int, HallBooking>  $others
     * @return Collection<int, HallBooking>
     */
    public function conflicts(HallBooking $booking, Collection $others): Collection
    {
        if ($booking->team?->archived_at !== null) {
            return new Collection;
        }

        return $others->filter(fn (HallBooking $o) => $o->id !== $booking->id
            && $o->team !== null && $o->team->archived_at === null
            && $o->hall_id === $booking->hall_id
            && $o->weekday === $booking->weekday
            && self::hm($o->start_time) < self::hm($booking->end_time) && self::hm($booking->start_time) < self::hm($o->end_time)
            && self::periodsOverlap($o->valid_from?->toDateString(), $o->valid_until?->toDateString(), $booking->valid_from?->toDateString(), $booking->valid_until?->toDateString())
            && self::courtsOverlap($o->courtList(), $booking->courtList()))->values();
    }

    /**
     * Whether a hall time covers the booking – time and parts of the hall.
     * Halls without times entered do not warn.
     *
     * @param  Collection<int, HallSlot>  $slots
     */
    public function withinSlots(HallBooking $booking, Collection $slots): bool
    {
        if ($slots->isEmpty()) {
            return true;
        }

        return $slots->contains(fn (HallSlot $slot) => $slot->weekday === $booking->weekday
            && self::hm($slot->start_time) <= self::hm($booking->start_time) && self::hm($slot->end_time) >= self::hm($booking->end_time)
            && self::courtsWithin($booking->courtList(), $slot->courtList())
            && self::periodsOverlap($slot->valid_from?->toDateString(), $slot->valid_until?->toDateString(), $booking->valid_from?->toDateString(), $booking->valid_until?->toDateString()));
    }

    /**
     * Times of one team (for the team page).
     *
     * @return list<array<string, mixed>>
     */
    public function forTeam(string $teamId): array
    {
        return HallBooking::query()->with('hall')->where('team_id', $teamId)->orderBy('weekday')->orderBy('start_time')->get()
            ->filter(fn (HallBooking $b) => $b->hall !== null && ($b->valid_until === null || $b->valid_until->toDateString() >= now()->toDateString()))
            ->map(fn (HallBooking $b) => [
                'id' => $b->id,
                'hall' => ['id' => $b->hall->id, 'name' => $b->hall->name, 'address' => $b->hall->address],
                'weekday' => $b->weekday,
                'start_time' => self::hm($b->start_time),
                'end_time' => self::hm($b->end_time),
                'courts' => $b->courtList(),
                'valid_from' => $b->valid_from?->toDateString(),
                'valid_until' => $b->valid_until?->toDateString(),
            ])->values()->all();
    }

    /**
     * @param  list<int>|null  $used  null = whole hall
     * @param  list<int>|null  $available  null = whole hall
     */
    private static function courtsWithin(?array $used, ?array $available): bool
    {
        return $available === null || ($used !== null && array_diff($used, $available) === []);
    }

    /** @param  list<int>|null  $a  null = whole hall */
    private static function courtsOverlap(?array $a, ?array $b): bool
    {
        return $a === null || $b === null || array_intersect($a, $b) !== [];
    }

    private static function periodsOverlap(?string $fromA, ?string $untilA, ?string $fromB, ?string $untilB): bool
    {
        return ($fromA === null || $untilB === null || $fromA <= $untilB)
            && ($fromB === null || $untilA === null || $fromB <= $untilA);
    }

    /** "19:30:00" → "19:30" */
    public static function hm(string $time): string
    {
        return substr($time, 0, 5);
    }
}
