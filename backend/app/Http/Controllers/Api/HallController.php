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
use Illuminate\Support\Facades\DB;

/**
 * Halls, their available times and which team trains when. Every member
 * sees the plan, only admins change it. Overlaps are reported, not blocked.
 * The times are training times – match days and other events stay apart.
 */
class HallController extends Controller
{
    public function __construct(private CurrentMember $current, private Permissions $permissions, private HallPlan $plan) {}

    public function index(): JsonResponse
    {
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
        $hall = Hall::create($this->hallData($request));
        $this->saveOpeningHours($request, $hall);

        return $this->plan(201);
    }

    public function update(Request $request, Hall $hall): JsonResponse
    {
        $this->authorizeAdmin();
        $data = $this->hallData($request);
        $court = HallBooking::query()->where('hall_id', $hall->id)->get()->toBase()->map(fn (HallBooking $b) => $b->courtList())
            ->merge(HallSlot::query()->where('hall_id', $hall->id)->get()->map(fn (HallSlot $s) => $s->courtList()))
            ->flatMap(fn (?array $courts) => $courts ?? [])->filter(fn (int $c) => $c > $data['courts'])->min();
        abort_if($court !== null, 422, __('halls.courts_in_use', ['court' => $court]));

        $hall->update($data);
        $this->saveOpeningHours($request, $hall);

        return $this->plan();
    }

    /**
     * The weekly hall times like opening hours of a shop ("Mo 18–20, Di …").
     * Replaces the times without a period; times limited to a period
     * (holidays, a season) stay as they are.
     */
    private function saveOpeningHours(Request $request, Hall $hall): void
    {
        if (! $request->has('opening_hours')) {
            return;
        }
        $data = $request->validate([
            'opening_hours' => ['present', 'array', 'max:50'],
            'opening_hours.*.weekday' => ['required', 'integer', 'between:1,7'],
            'opening_hours.*.start_time' => ['required', 'date_format:H:i'],
            'opening_hours.*.end_time' => ['required', 'date_format:H:i'],
            'opening_hours.*.courts' => ['nullable', 'array', 'max:12'],
            'opening_hours.*.courts.*' => ['integer', 'min:1'],
        ]);
        $rows = [];
        foreach ($data['opening_hours'] as $row) {
            abort_if($row['end_time'] <= $row['start_time'], 422, __('halls.end_after_start'));
            $courts = array_values(array_unique(array_map('intval', $row['courts'] ?? [])));
            sort($courts);
            abort_if($courts !== [] && max($courts) > $hall->courts, 422, __('halls.court_too_high', ['courts' => $hall->courts]));
            $rows[] = [
                'weekday' => (int) $row['weekday'],
                'start_time' => $row['start_time'].':00',
                'end_time' => $row['end_time'].':00',
                'courts' => $courts === [] || count($courts) === $hall->courts ? null : $courts,
            ];
        }

        DB::transaction(function () use ($hall, $rows) {
            HallSlot::query()->where('hall_id', $hall->id)->whereNull('valid_from')->whereNull('valid_until')->delete();
            foreach ($rows as $row) {
                HallSlot::create(['hall_id' => $hall->id] + $row);
            }
        });
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
        HallSlot::create(['hall_id' => $hall->id] + $this->timeData($request) + ['courts' => $this->courts($request, $hall)]);

        return $this->plan(201);
    }

    public function updateSlot(Request $request, HallSlot $slot): JsonResponse
    {
        $this->authorizeAdmin();
        $slot->update($this->timeData($request) + ['courts' => $this->courts($request, $slot->hall)]);

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
            'street' => ['nullable', 'string', 'max:120'],
            'house_number' => ['nullable', 'string', 'max:20'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'city' => ['nullable', 'string', 'max:80'],
            'courts' => ['required', 'integer', 'between:1,12'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'contact_email' => ['nullable', 'email', 'max:255'],
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
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $team = Team::query()->whereKey($data['team_id'])->whereNull('archived_at')->first();
        abort_if($team === null, 422, __('events.teams_invalid'));

        return $this->timeData($request) + ['team_id' => $team->id, 'courts' => $this->courts($request, $hall), 'notes' => $data['notes'] ?? null];
    }

    /**
     * Parts of the hall; empty or all parts = whole hall (null).
     *
     * @return list<int>|null
     */
    private function courts(Request $request, Hall $hall): ?array
    {
        $data = $request->validate([
            'courts' => ['nullable', 'array', 'max:12'],
            'courts.*' => ['integer', 'min:1', 'distinct'],
        ]);
        $courts = array_map('intval', $data['courts'] ?? []);
        sort($courts);
        abort_if($courts !== [] && max($courts) > $hall->courts, 422, __('halls.court_too_high', ['courts' => $hall->courts]));

        return $courts === [] || count($courts) === $hall->courts ? null : $courts;
    }
}
