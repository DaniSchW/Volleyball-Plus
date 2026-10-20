<?php

namespace App\Support\Tasks;

use App\Models\Event;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\Team;
use App\Models\TenantMember;
use App\Support\Auth\Permissions;
use Illuminate\Support\Collection;

/**
 * Task templates: who may use them and taking them over into events.
 * Department templates (team null) can be used by everybody who manages
 * events, changed only by admins; team templates by the team's trainers,
 * organizers and admins.
 */
class TaskTemplates
{
    public function __construct(private Permissions $permissions) {}

    /** @return Collection<int, TaskTemplate> */
    public function usable(TenantMember $member): Collection
    {
        return TaskTemplate::query()->with('team')->orderBy('name')->get()
            ->filter(fn (TaskTemplate $t) => $t->team_id === null ? $this->managesAnyTeam($member) : ($t->team !== null && $this->permissions->canManageEventsOf($member, $t->team)))
            ->values();
    }

    public function canChange(TenantMember $member, ?Team $team): bool
    {
        return $team === null ? $this->permissions->isAdmin($member) : $this->permissions->canManageEventsOf($member, $team);
    }

    public function canUse(TenantMember $member, TaskTemplate $template): bool
    {
        return $this->usable($member)->contains('id', $template->id);
    }

    /**
     * Adds the template's tasks to the event; tasks with the same title
     * that the event already has are left out. Returns the number added.
     */
    public function apply(TaskTemplate $template, Event $event): int
    {
        $existing = Task::query()->where('event_id', $event->id)->pluck('title')->map(fn ($t) => mb_strtolower($t))->flip();
        $order = (int) Task::query()->where('event_id', $event->id)->max('sort_order');
        $added = 0;
        foreach ($template->items as $item) {
            if ($existing->has(mb_strtolower($item['title']))) {
                continue;
            }
            Task::create([
                'event_id' => $event->id,
                'title' => $item['title'],
                'description' => $item['description'] ?? null,
                'slots' => $item['slots'] ?? null,
                'sort_order' => ++$order,
            ]);
            $added++;
        }

        return $added;
    }

    /**
     * The tasks of an event as template items.
     *
     * @return list<array{title: string, description: string|null, slots: int|null}>
     */
    public function itemsOf(Event $event): array
    {
        return Task::query()->where('event_id', $event->id)->orderBy('sort_order')->get()
            ->map(fn (Task $t) => ['title' => $t->title, 'description' => $t->description, 'slots' => $t->slots])->values()->all();
    }

    private function managesAnyTeam(TenantMember $member): bool
    {
        return $this->permissions->isAdmin($member)
            || Team::query()->whereNull('archived_at')->get()->contains(fn (Team $t) => $this->permissions->canManageEventsOf($member, $t));
    }
}
