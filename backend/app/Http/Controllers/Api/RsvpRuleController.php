<?php

namespace App\Http\Controllers\Api;

use App\Enums\EventType;
use App\Enums\RsvpStatus;
use App\Http\Controllers\Controller;
use App\Models\CrossNomination;
use App\Models\EventSeries;
use App\Models\Person;
use App\Models\RsvpRule;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Support\Auth\CurrentMember;
use App\Support\Auth\Permissions;
use App\Support\Events\RsvpRules;
use App\Support\Statistics\AttendanceStatistics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Standing replies of the own person and the children: answer many events
 * at once ("Wednesday trainings yes, Friday trainings no").
 */
class RsvpRuleController extends Controller
{
    public function __construct(private CurrentMember $current, private Permissions $permissions, private RsvpRules $rules, private AttendanceStatistics $statistics) {}

    public function index(): JsonResponse
    {
        $member = $this->current->get();
        $persons = Person::query()->whereIn('id', $this->permissions->actingPersonIds($member))->orderBy('first_name')->get()
            ->filter(fn (Person $p) => $this->permissions->canRespondFor($member, $p));

        $rules = RsvpRule::query()->with(['team', 'series'])->whereIn('person_id', $persons->pluck('id'))->orderByDesc('created_at')->get();

        return response()->json(['data' => [
            'rules' => $rules->map(fn (RsvpRule $r) => $this->present($r))->values()->all(),
            // For "until the end of the season".
            'season_end' => $this->statistics->presets()['season']['to'],
            'persons' => $persons->map(fn (Person $p) => [
                'id' => $p->id,
                'name' => $p->first_name.' '.$p->last_name,
                'teams' => $this->teamsOf($p),
            ])->values()->all(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $member = $this->current->get();
        $data = $request->validate([
            'person_id' => ['required', 'string'],
            'status' => ['required', Rule::enum(RsvpStatus::class)],
            'reason' => ['nullable', 'string', 'max:255'],
            'team_id' => ['nullable', 'string'],
            'series_id' => ['nullable', 'string'],
            'event_type' => ['nullable', Rule::in([EventType::Training->value, EventType::Match->value, EventType::Extra->value])],
            'weekdays' => ['nullable', 'array', 'max:7'],
            'weekdays.*' => ['integer', 'between:1,7', 'distinct'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'overwrite' => ['sometimes', 'boolean'],
        ]);
        $person = Person::query()->findOrFail($data['person_id']);
        abort_unless($this->permissions->canRespondFor($member, $person), 403);

        $teams = collect($this->teamsOf($person));
        abort_if(($data['team_id'] ?? null) !== null && ! $teams->contains('id', $data['team_id']), 422, __('events.teams_invalid'));
        $series = isset($data['series_id']) ? EventSeries::query()->find($data['series_id']) : null;
        abort_if(isset($data['series_id']) && ($series === null || ! $teams->contains('id', $series->team_id)), 422, __('events.series_invalid'));

        $weekdays = array_values(array_unique(array_map('intval', $data['weekdays'] ?? [])));
        sort($weekdays);
        $rule = new RsvpRule([
            'person_id' => $person->id,
            'team_id' => $series?->team_id ?? ($data['team_id'] ?? null),
            'series_id' => $series?->id,
            'event_type' => $series !== null ? null : ($data['event_type'] ?? null),
            'weekdays' => $series !== null || $weekdays === [] ? null : $weekdays,
            'valid_until' => $data['valid_until'] ?? null,
            'status' => $data['status'],
            'reason' => $data['status'] === RsvpStatus::Yes->value ? null : (trim((string) ($data['reason'] ?? '')) ?: null),
            'created_by' => $member->id,
        ]);

        $result = $this->rules->create($rule, $member, (bool) ($data['overwrite'] ?? true));

        return response()->json(['data' => $this->present($rule->load(['team', 'series']))] + $result, 201);
    }

    /** Removing the rule keeps the answers it gave. */
    public function destroy(RsvpRule $rule): JsonResponse
    {
        $member = $this->current->get();
        $person = Person::query()->findOrFail($rule->person_id);
        abort_unless($this->permissions->canRespondFor($member, $person), 403);
        $rule->delete();

        return response()->json(['message' => 'ok']);
    }

    /** @return array<string, mixed> */
    private function present(RsvpRule $rule): array
    {
        return [
            'id' => $rule->id,
            'person_id' => $rule->person_id,
            'status' => $rule->status->value,
            'reason' => $rule->reason,
            'team' => $rule->team ? ['id' => $rule->team->id, 'name' => $rule->team->name] : null,
            'series' => $rule->series ? $this->presentSeries($rule->series) : null,
            'event_type' => $rule->event_type?->value,
            'weekdays' => $rule->weekdays,
            'valid_until' => $rule->valid_until?->toDateString(),
            'created_at' => $rule->created_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * Teams the person plays/trains in (also as guest) with their running series.
     *
     * @return list<array<string, mixed>>
     */
    private function teamsOf(Person $person): array
    {
        $ids = TeamMembership::query()->where('person_id', $person->id)->pluck('team_id')
            ->merge(CrossNomination::query()->where('person_id', $person->id)->where('active', true)->pluck('host_team_id'))->unique();
        $teams = Team::query()->whereIn('id', $ids)->whereNull('archived_at')->orderBy('name')->get();
        $series = EventSeries::query()->whereIn('team_id', $teams->pluck('id'))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', Carbon::today()->toDateString()))
            ->orderBy('start_time')->get()->groupBy('team_id');

        return $teams->map(fn (Team $t) => [
            'id' => $t->id,
            'name' => $t->name,
            'series' => ($series[$t->id] ?? collect())->map(fn (EventSeries $s) => $this->presentSeries($s))->values()->all(),
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    private function presentSeries(EventSeries $series): array
    {
        return [
            'id' => $series->id,
            'title' => $series->title,
            'weekdays' => array_map('intval', $series->weekdays ?? []),
            'start_time' => substr((string) $series->start_time, 0, 5),
            'end_time' => substr((string) $series->end_time, 0, 5),
        ];
    }
}
