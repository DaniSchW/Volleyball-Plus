<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventSeries;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\TrainingGroup;
use App\Models\TrainingGroupMember;
use App\Support\Auth\CurrentMember;
use App\Support\Auth\Permissions;
use App\Support\Events\InviteeSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Groups within a team with their own trainings (e.g. setter training).
 * Members are chosen from the team; trainers and organizers of the team
 * are always invited to group trainings.
 */
class TrainingGroupController extends Controller
{
    public function __construct(private CurrentMember $current, private Permissions $permissions, private InviteeSync $invitees) {}

    public function index(Team $team): JsonResponse
    {
        abort_unless($this->permissions->canSeeTeam($this->current->get(), $team), 403);

        $groups = TrainingGroup::query()->where('team_id', $team->id)->with('members')->orderBy('name')->get();

        return response()->json(['data' => $groups->map(fn (TrainingGroup $g) => $this->present($g))->values()->all()]);
    }

    public function store(Request $request, Team $team): JsonResponse
    {
        abort_unless($this->permissions->canManageTeam($this->current->get(), $team), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);

        $group = TrainingGroup::create(['team_id' => $team->id, 'name' => $data['name']]);

        return response()->json(['data' => $this->present($group->load('members'))], 201);
    }

    /** Rename and/or set the members (a complete list of person ids). */
    public function update(Request $request, TrainingGroup $group): JsonResponse
    {
        abort_unless($this->permissions->canManageTeam($this->current->get(), $group->team), 403);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'person_ids' => ['sometimes', 'array', 'max:200'],
            'person_ids.*' => ['string', 'distinct'],
        ]);

        DB::transaction(function () use ($group, $data) {
            if (isset($data['name'])) {
                $group->fill(['name' => $data['name']])->save();
            }
            if (array_key_exists('person_ids', $data)) {
                $teamPersons = TeamMembership::query()->where('team_id', $group->team_id)->pluck('person_id')->flip();
                abort_unless(collect($data['person_ids'])->every(fn ($id) => $teamPersons->has($id)), 422, __('teams.group_member_invalid'));

                TrainingGroupMember::query()->where('training_group_id', $group->id)->whereNotIn('person_id', $data['person_ids'])->delete();
                foreach ($data['person_ids'] as $personId) {
                    TrainingGroupMember::query()->firstOrCreate(['training_group_id' => $group->id, 'person_id' => $personId]);
                }
            }
        });
        $this->invitees->syncTeam($group->team_id);

        return response()->json(['data' => $this->present($group->load('members'))]);
    }

    /** Group trainings become trainings of the whole team. */
    public function destroy(TrainingGroup $group): JsonResponse
    {
        abort_unless($this->permissions->canManageTeam($this->current->get(), $group->team), 403);

        DB::transaction(function () use ($group) {
            Event::query()->where('training_group_id', $group->id)->update(['training_group_id' => null]);
            EventSeries::query()->where('training_group_id', $group->id)->update(['training_group_id' => null]);
            $group->members()->delete();
            $group->delete();
        });
        $this->invitees->syncTeam($group->team_id);

        return response()->json(['message' => 'ok']);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(TrainingGroup $group): array
    {
        return [
            'id' => $group->id,
            'team_id' => $group->team_id,
            'name' => $group->name,
            'person_ids' => $group->members->pluck('person_id')->values()->all(),
        ];
    }
}
