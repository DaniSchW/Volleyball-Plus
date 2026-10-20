<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventSeries;
use App\Models\TaskTemplate;
use App\Models\Team;
use App\Support\Auth\CurrentMember;
use App\Support\Auth\Permissions;
use App\Support\Tasks\TaskTemplates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Task templates: save the tasks of an event (or a list) under a name and
 * take them over into events – one or many at once (e.g. all home matches).
 */
class TaskTemplateController extends Controller
{
    public function __construct(private CurrentMember $current, private Permissions $permissions, private TaskTemplates $templates) {}

    public function index(): JsonResponse
    {
        $member = $this->current->get();
        $teams = Team::query()->whereNull('archived_at')->orderBy('name')->get()
            ->filter(fn (Team $t) => $this->permissions->canManageEventsOf($member, $t))->values();

        return response()->json(['data' => [
            'templates' => $this->templates->usable($member)->map(fn (TaskTemplate $t) => $this->present($t))->all(),
            'teams' => $teams->map(fn (Team $t) => ['id' => $t->id, 'name' => $t->name])->all(),
            'can_create_for_all' => $this->permissions->isAdmin($member),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $member = $this->current->get();
        $data = $request->validate($this->rules(true) + ['event_id' => ['nullable', 'string']]);
        $team = $this->team($data['team_id'] ?? null);
        abort_unless($this->templates->canChange($member, $team), 403);

        if (! empty($data['event_id'])) {
            $event = Event::query()->findOrFail($data['event_id']);
            abort_unless($this->permissions->canManageEvent($member, $event), 403);
            $items = $this->templates->itemsOf($event);
            abort_if($items === [], 422, __('tasks.template_empty'));
        } else {
            $items = $this->items($data['items'] ?? []);
            abort_if($items === [], 422, __('tasks.template_empty'));
        }

        $template = TaskTemplate::create(['team_id' => $team?->id, 'name' => trim($data['name']), 'items' => $items, 'created_by' => $member->id]);

        return response()->json(['data' => $this->present($template->load('team'))], 201);
    }

    public function update(Request $request, TaskTemplate $template): JsonResponse
    {
        $member = $this->current->get();
        abort_unless($this->templates->canChange($member, $template->team), 403);
        $data = $request->validate($this->rules(false));
        $team = array_key_exists('team_id', $data) ? $this->team($data['team_id']) : $template->team;
        abort_unless($this->templates->canChange($member, $team), 403);

        $items = array_key_exists('items', $data) ? $this->items($data['items'] ?? []) : $template->items;
        abort_if($items === [], 422, __('tasks.template_empty'));
        $template->update(['team_id' => $team?->id, 'name' => trim($data['name'] ?? $template->name), 'items' => $items]);

        return response()->json(['data' => $this->present($template->load('team'))]);
    }

    public function destroy(TaskTemplate $template): JsonResponse
    {
        abort_unless($this->templates->canChange($this->current->get(), $template->team), 403);
        // Series keep their tasks; they just stop taking new ones over.
        EventSeries::query()->where('task_template_id', $template->id)->update(['task_template_id' => null]);
        $template->delete();

        return response()->json(['message' => 'ok']);
    }

    /** Take over into one or many upcoming events (skips tasks the event already has). */
    public function apply(Request $request, TaskTemplate $template): JsonResponse
    {
        $member = $this->current->get();
        abort_unless($this->templates->canUse($member, $template), 403);
        $data = $request->validate(['event_ids' => ['required', 'array', 'min:1', 'max:100'], 'event_ids.*' => ['string']]);

        $events = Event::query()->with('teams')->whereIn('id', $data['event_ids'])->get()
            ->filter(fn (Event $e) => ! $e->isCancelled() && $e->ends_at->isAfter(Carbon::now()) && $this->permissions->canManageEvent($member, $e));
        $added = $events->sum(fn (Event $e) => $this->templates->apply($template, $e));

        return response()->json(['data' => ['events' => $events->count(), 'tasks' => $added], 'message' => __('tasks.template_applied', ['events' => $events->count(), 'tasks' => $added])]);
    }

    /** @return array<string, mixed> */
    private function rules(bool $creating): array
    {
        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'team_id' => ['sometimes', 'nullable', 'string'],
            'items' => ['sometimes', 'array', 'max:40'],
            'items.*.title' => ['required', 'string', 'max:120'],
            'items.*.description' => ['nullable', 'string', 'max:500'],
            'items.*.slots' => ['nullable', 'integer', 'between:1,99'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return list<array{title: string, description: string|null, slots: int|null}>
     */
    private function items(array $items): array
    {
        return collect($items)->map(fn (array $i) => [
            'title' => trim($i['title']),
            'description' => trim((string) ($i['description'] ?? '')) ?: null,
            'slots' => isset($i['slots']) ? (int) $i['slots'] : null,
        ])->filter(fn ($i) => $i['title'] !== '')->values()->all();
    }

    private function team(?string $id): ?Team
    {
        if ($id === null || $id === '') {
            return null;
        }
        $team = Team::query()->whereKey($id)->whereNull('archived_at')->first();
        abort_if($team === null, 422, __('events.teams_invalid'));

        return $team;
    }

    /** @return array<string, mixed> */
    private function present(TaskTemplate $template): array
    {
        return [
            'id' => $template->id,
            'name' => $template->name,
            'team' => $template->team ? ['id' => $template->team->id, 'name' => $template->team->name] : null,
            'items' => $template->items,
            'can_change' => $this->templates->canChange($this->current->get(), $template->team),
        ];
    }
}
