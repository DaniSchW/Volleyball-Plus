<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hall;
use App\Models\HallBooking;
use App\Models\HallSlot;
use App\Models\Team;
use App\Support\Auth\CurrentMember;
use App\Support\Auth\Permissions;
use App\Support\Halls\HallPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Halls, their available times and which team trains when. Trainers see
 * the plan, only admins change it. Overlaps are reported, not blocked.
 */
class HallController extends Controller
{
    public function __construct(private CurrentMember $current, private Permissions $permissions, private HallPlan $plan) {}

    public function index(): JsonResponse
    {
        abort_unless($this->permissions->canSeeHalls($this->current->get()), 403);

        return $this->plan();
    }

    /** Training times of one team – for everybody who sees the team. */
    public function team(Team $team): JsonResponse
    {
        abort_unless($this->permissions->canSeeTeam($this->current->get(), $team), 403);

        return response()->json(['data' => $this->plan->forTeam($team->id)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdmin();
        Hall::create($this->hallData($request));

        return $this->plan(201);
    }

    public function update(Request $request, Hall $hall): JsonResponse
    {
        $this->authorizeAdmin();
        $data = $this->hallData($request);
        $court = HallBooking::query()->where('hall_id', $hall->id)->where('court', '>', $data['courts'])->min('court');
        abort_if($court !== null, 422, __('halls.courts_in_use', ['court' => $court]));

        $hall->update($data);

        return $this->plan();
    }

    public function destroy(Hall $hall): JsonResponse
    {
        $this->authorizeAdmin();
        $hall->bookings()->delete();
        $hall->slots()->delete();
        $hall->delete();

        return $this->plan();
    }

    public function storeSlot(Request $request, Hall $hall): JsonResponse
    {
        $this->authorizeAdmin();
        HallSlot::create(['hall_id' => $hall->id] + $this->timeData($request));

        return $this->plan(201);
    }

    public function updateSlot(Request $request, HallSlot $slot): JsonResponse
    {
        $this->authorizeAdmin();
        $slot->update($this->timeData($request));

        return $this->plan();
    }

    public function destroySlot(HallSlot $slot): JsonResponse
    {
        $this->authorizeAdmin();
        $slot->delete();

        return $this->plan();
    }

    public function storeBooking(Request $request, Hall $hall): JsonResponse
    {
        $this->authorizeAdmin();
        $booking = HallBooking::create(['hall_id' => $hall->id] + $this->bookingData($request, $hall));

        return $this->plan(201, $booking->id);
    }

    public function updateBooking(Request $request, HallBooking $booking): JsonResponse
    {
        $this->authorizeAdmin();
        $booking->update($this->bookingData($request, $booking->hall));

        return $this->plan(200, $booking->id);
    }

    public function destroyBooking(HallBooking $booking): JsonResponse
    {
        $this->authorizeAdmin();
        $booking->delete();

        return $this->plan();
    }

    /** The whole plan; after saving a booking also its warnings. */
    private function plan(int $status = 200, ?string $bookingId = null): JsonResponse
    {
        $halls = $this->plan->present();
        $saved = null;
        if ($bookingId !== null) {
            foreach ($halls as $hall) {
                foreach ($hall['bookings'] as $booking) {
                    if ($booking['id'] === $bookingId) {
                        $saved = $booking;
                    }
                }
            }
        }

        return response()->json(['data' => $halls, 'booking' => $saved], $status);
    }

    private function authorizeAdmin(): void
    {
        abort_unless($this->permissions->isAdmin($this->current->get()), 403);
    }

    /** @return array<string, mixed> */
    private function hallData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'courts' => ['required', 'integer', 'between:1,12'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    /** @return array<string, mixed> */
    private function timeData(Request $request): array
    {
        $data = $request->validate([
            'weekday' => ['required', 'integer', 'between:1,7'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
        ]);
        abort_if($data['end_time'] <= $data['start_time'], 422, __('halls.end_after_start'));

        return [
            'weekday' => $data['weekday'],
            'start_time' => $data['start_time'].':00',
            'end_time' => $data['end_time'].':00',
            'valid_from' => $data['valid_from'] ?? null,
            'valid_until' => $data['valid_until'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function bookingData(Request $request, Hall $hall): array
    {
        $data = $request->validate([
            'team_id' => ['required', 'string'],
            'court' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $team = Team::query()->whereKey($data['team_id'])->whereNull('archived_at')->first();
        abort_if($team === null, 422, __('events.teams_invalid'));
        abort_if(($data['court'] ?? null) !== null && $data['court'] > $hall->courts, 422, __('halls.court_too_high', ['courts' => $hall->courts]));

        return $this->timeData($request) + ['team_id' => $team->id, 'court' => $data['court'] ?? null, 'notes' => $data['notes'] ?? null];
    }
}
