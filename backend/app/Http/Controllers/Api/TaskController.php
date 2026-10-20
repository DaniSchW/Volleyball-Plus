<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventInvitee;
use App\Models\Person;
use App\Models\Task;
use App\Support\Auth\CurrentMember;
use App\Support\Auth\Permissions;
use App\Support\Events\EventPresenter;
use App\Support\Tasks\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Tasks of events ("Wer fährt?", helper services). Trainers, organizers
 * and admins define them; invited people take places.
 */
class TaskController extends Controller
{
    public function __construct(private CurrentMember $current, private Permissions $permissions, private EventPresenter $presenter) {}

    /**
     * Upcoming tasks of the member (own and children's) and helper services
     * with free places they are invited to.
     */
    public function index(): JsonResponse
    {
        $member = $this->current->get();
        $persons = $this->permissions->actingPersonIds($member);
        $now = Carbon::now();

        $invitedEvents = EventInvitee::query()->whereIn('person_id', $persons)->select('event_id');
        $events = Event::query()->with(['tasks.assignments.person', 'teams'])
            ->whereIn('id', $invitedEvents)
            ->where('ends_at', '>=', $now)
            ->where('status', 'scheduled')
            ->whereHas('tasks')
            ->orderBy('starts_at')->limit(100)->get();

        $rows = $events->map(function (Event $event) use ($persons) {
            $tasks = $event->tasks->map(fn (Task $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'slots' => $task->slots,
                'taken' => $task->assignments->count(),
                'mine' => $task->assignments->whereIn('person_id', $persons)->map(fn ($a) => $a->person?->first_name)->values()->all(),
            ]);
            $mine = $tasks->filter(fn ($t) => $t['mine'] !== []);
            // Without a limit a task always has room.
            $open = $tasks->sum(fn ($t) => $t['slots'] === null ? 1 : max(0, $t['slots'] - $t['taken']));

            return [
                'event' => [
                    'id' => $event->id, 'type' => $event->type->value, 'title' => $event->title,
                    'starts_at' => $event->starts_at->toIso8601ZuluString(),
                    'teams' => $event->teams->pluck('name')->all(),
                ],
                'mine' => $mine->values()->all(),
                'open_slots' => $open,
            ];
        })->filter(fn ($row) => $row['mine'] !== [] || ($row['event']['type'] === 'helper' && $row['open_slots'] > 0))->values();

        return response()->json(['data' => $rows->all()]);
    }

    public function store(Request $request, Event $event): JsonResponse
    {
        abort_unless($this->permissions->canManageEvent($this->current->get(), $event), 403);
        $data = $request->validate($this->rules(required: true));

        Task::create($data + ['event_id' => $event->id, 'sort_order' => (int) Task::query()->where('event_id', $event->id)->max('sort_order') + 1]);

        return $this->detail($event, 201);
    }

    public function update(Request $request, Task $task): JsonResponse
    {
        abort_unless($this->permissions->canManageEvent($this->current->get(), $task->event), 403);
        $data = $request->validate($this->rules(required: false));

        $taken = $task->assignments()->count();
        if (($data['slots'] ?? null) !== null && $data['slots'] < $taken) {
            throw ValidationException::withMessages(['slots' => __('tasks.slots_below_taken', ['count' => $taken])]);
        }
        $task->fill($data)->save();

        return $this->detail($task->event);
    }

    public function destroy(Task $task): JsonResponse
    {
        abort_unless($this->permissions->canManageEvent($this->current->get(), $task->event), 403);

        $task->assignments()->delete();
        $task->delete();

        return $this->detail($task->event);
    }

    public function take(Request $request, Task $task, TaskService $tasks): JsonResponse
    {
        $data = $request->validate(['person_id' => ['required', 'string']]);
        $tasks->take($task, Person::query()->findOrFail($data['person_id']), $this->current->get());

        return $this->detail($task->event);
    }

    public function release(Task $task, Person $person, TaskService $tasks): JsonResponse
    {
        $tasks->release($task, $person, $this->current->get());

        return $this->detail($task->event);
    }

    private function detail(Event $event, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->presenter->detail($event->refresh(), $this->current->get())], $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $required): array
    {
        return [
            'title' => [$required ? 'required' : 'sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'slots' => ['sometimes', 'nullable', 'integer', 'between:1,99'],
        ];
    }
}
