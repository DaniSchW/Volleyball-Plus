<?php

namespace App\Http\Controllers\Api;

use App\Enums\EventScope;
use App\Http\Controllers\Controller;
use App\Models\Person;
use App\Models\Poll;
use App\Models\PollTeam;
use App\Models\Team;
use App\Support\Auth\CurrentMember;
use App\Support\Auth\Permissions;
use App\Support\Polls\PollService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Polls per team; admins also for all teams at once.
 */
class PollController extends Controller
{
    public function __construct(private CurrentMember $current, private Permissions $permissions, private PollService $polls) {}

    /** Open polls first, then closed ones of the last 90 days. */
    public function index(): JsonResponse
    {
        $member = $this->current->get();
        $now = Carbon::now();

        $polls = Poll::query()->with(['teams', 'options'])
            ->whereIn('id', PollTeam::query()->whereIn('team_id', $this->polls->visibleTeamIds($member))->select('poll_id'))
            ->where(fn ($q) => $q->whereNull('closes_at')->orWhere('closes_at', '>=', $now->copy()->subDays(90)))
            ->latest()->limit(100)->get()
            ->filter(fn (Poll $p) => $this->polls->canSee($member, $p))
            ->sortBy(fn (Poll $p) => $p->isClosed() ? 1 : 0)->values();

        return response()->json(['data' => $polls->map(fn (Poll $p) => $this->polls->present($p, $member))->all()]);
    }

    public function show(Poll $poll): JsonResponse
    {
        $member = $this->current->get();
        abort_unless($this->polls->canSee($member, $poll), 403);

        return response()->json(['data' => $this->polls->present($poll, $member)]);
    }

    public function store(Request $request): JsonResponse
    {
        $member = $this->current->get();
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'scope' => ['required', Rule::enum(EventScope::class)],
            'team_ids' => ['required_if:scope,team', 'array', 'max:50'],
            'team_ids.*' => ['string'],
            'options' => ['required', 'array', 'min:1', 'max:20'],
            'options.*' => ['required', 'string', 'max:160', 'distinct'],
            'multiple_choice' => ['boolean'],
            'anonymous' => ['boolean'],
            'allow_free_text' => ['boolean'],
            'closes_at' => ['nullable', 'date', 'after:now'],
        ]);

        if ($data['scope'] === EventScope::Tenant->value) {
            abort_unless($this->permissions->isAdmin($member), 403);
            $teams = Team::query()->whereNull('archived_at')->get();
        } else {
            $teams = Team::query()->whereIn('id', $data['team_ids'])->whereNull('archived_at')->get();
            abort_if($teams->isEmpty() || $teams->count() !== count(array_unique($data['team_ids'])), 422, __('events.teams_invalid'));
            abort_unless($teams->every(fn (Team $t) => $this->permissions->canManageEventsOf($member, $t)), 403);
        }
        if (! empty($data['closes_at'])) {
            $data['closes_at'] = Carbon::parse($data['closes_at'], $member->tenant->timezone)->utc();
        }

        $poll = $this->polls->create($data, $teams, $member);

        return response()->json(['data' => $this->polls->present($poll->refresh(), $member)], 201);
    }

    public function vote(Request $request, Poll $poll): JsonResponse
    {
        $member = $this->current->get();
        $data = $request->validate([
            'person_id' => ['required', 'string'],
            'option_ids' => ['present', 'array', 'max:20'],
            'option_ids.*' => ['string'],
            'free_text' => ['nullable', 'string', 'max:500'],
        ]);

        $this->polls->vote($poll, Person::query()->findOrFail($data['person_id']), $data['option_ids'], $data['free_text'] ?? null, $member);

        return response()->json(['data' => $this->polls->present($poll, $member)]);
    }

    public function withdraw(Poll $poll, Person $person): JsonResponse
    {
        $member = $this->current->get();
        $this->polls->withdraw($poll, $person, $member);

        return response()->json(['data' => $this->polls->present($poll, $member)]);
    }

    public function close(Poll $poll): JsonResponse
    {
        $member = $this->current->get();
        abort_unless($this->polls->canManage($member, $poll), 403);

        $poll->forceFill(['closes_at' => Carbon::now()])->save();

        return response()->json(['data' => $this->polls->present($poll, $member)]);
    }

    public function destroy(Poll $poll): JsonResponse
    {
        abort_unless($this->polls->canManage($this->current->get(), $poll), 403);

        $poll->votes()->delete();
        $poll->options()->delete();
        PollTeam::query()->where('poll_id', $poll->id)->delete();
        $poll->delete();

        return response()->json(['message' => 'ok']);
    }
}
