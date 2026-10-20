<?php

namespace App\Support\Tasks;

use App\Models\EventInvitee;
use App\Models\Person;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TenantMember;
use App\Support\Auth\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Taking and giving back tasks. Invited persons take places themselves
 * (parents for their children); people managing the event can put anybody
 * invited on a task. Places are counted under a row lock, so two people
 * cannot take the last place at the same time.
 */
class TaskService
{
    public function __construct(private Permissions $permissions) {}

    public function take(Task $task, Person $person, TenantMember $actor): TaskAssignment
    {
        $event = $task->event;
        $manager = $this->permissions->canManageEvent($actor, $event);

        if (! $manager && ! $this->permissions->canTakeTasksFor($actor, $person)) {
            throw new AccessDeniedHttpException(__('tasks.not_allowed'));
        }
        if (! EventInvitee::query()->where('event_id', $event->id)->where('person_id', $person->id)->exists()) {
            throw ValidationException::withMessages(['person_id' => __('tasks.not_invited')]);
        }
        if (! $manager && ($event->isCancelled() || $event->starts_at->isPast())) {
            throw ValidationException::withMessages(['task' => __('tasks.closed')]);
        }

        return DB::transaction(function () use ($task, $person, $actor) {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            $existing = TaskAssignment::query()->where('task_id', $task->id)->where('person_id', $person->id)->first();
            if ($existing !== null) {
                return $existing;
            }
            if ($locked->slots !== null && TaskAssignment::query()->where('task_id', $task->id)->count() >= $locked->slots) {
                throw ValidationException::withMessages(['task' => __('tasks.full')]);
            }

            return TaskAssignment::create(['task_id' => $task->id, 'person_id' => $person->id, 'assigned_by' => $actor->id]);
        });
    }

    public function release(Task $task, Person $person, TenantMember $actor): void
    {
        $manager = $this->permissions->canManageEvent($actor, $task->event);
        if (! $manager && ! $this->permissions->canTakeTasksFor($actor, $person)) {
            throw new AccessDeniedHttpException(__('tasks.not_allowed'));
        }
        if (! $manager && $task->event->starts_at->isPast()) {
            throw ValidationException::withMessages(['task' => __('tasks.closed')]);
        }

        TaskAssignment::query()->where('task_id', $task->id)->where('person_id', $person->id)->delete();
    }
}
