<?php

namespace App\Http\Controllers\Api;

use App\Enums\Gender;
use App\Enums\PersonRelation;
use App\Enums\Position;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Models\MinorPermission;
use App\Models\Person;
use App\Models\RefereeLicense;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Support\Auth\CurrentMember;
use App\Support\Auth\Permissions;
use App\Support\Auth\PersonPresenter;
use App\Support\Files\ImageStore;
use App\Support\Privacy\PersonData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class PersonController extends Controller
{
    public function __construct(
        private CurrentMember $current,
        private Permissions $permissions,
        private PersonPresenter $presenter,
    ) {}

    /** Search persons of the department, e.g. to add them to a team (admins, trainers). */
    public function index(Request $request): JsonResponse
    {
        $member = $this->current->get();
        abort_unless($this->permissions->isAdmin($member) || $this->isAnyTrainer(), 403);

        $q = trim((string) $request->query('q', ''));
        $persons = Person::query()->with('teamMemberships.team')
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('first_name', 'like', '%'.addcslashes($q, '%_\\').'%')
                ->orWhere('last_name', 'like', '%'.addcslashes($q, '%_\\').'%')))
            ->orderBy('last_name')->orderBy('first_name')
            ->limit(50)
            ->get();

        return response()->json(['data' => $persons->map(fn (Person $p) => $this->presenter->present($p, $member) + [
            // Where the person already is – shown when picking players for a team.
            'teams' => $p->teamMemberships->filter(fn ($m) => $m->team && $m->team->archived_at === null)
                ->map(fn ($m) => ['id' => $m->team_id, 'name' => $m->team->name, 'role' => $m->role->value])->values()->all(),
        ])->all()]);
    }

    /**
     * Creates a profile without login (e.g. a child, before the parents are
     * invited) and puts it into a team.
     */
    public function store(Request $request): JsonResponse
    {
        $member = $this->current->get();
        $data = $request->validate([
            'team_id' => ['required', 'string'],
            'role' => ['required', Rule::enum(TeamRole::class)],
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'gender' => ['required', Rule::enum(Gender::class)],
            'birthday' => ['nullable', 'date', 'before:today'],
        ]);

        $team = Team::query()->findOrFail($data['team_id']);
        abort_unless($this->permissions->canManageTeam($member, $team), 403);

        $person = Person::create(Arr::only($data, ['first_name', 'last_name', 'gender', 'birthday']));
        TeamMembership::create(['team_id' => $team->id, 'person_id' => $person->id, 'role' => $data['role'], 'joined_at' => Carbon::today()]);

        return response()->json(['data' => $this->presenter->presentForEditing($person, $member)], 201);
    }

    public function show(Person $person): JsonResponse
    {
        $member = $this->current->get();
        $data = $this->presenter->presentForEditing($person, $member);

        $data['can_invite_guardian'] = $this->permissions->canInviteForPerson($member, $person, PersonRelation::Guardian);
        $data['can_invite_own'] = $this->permissions->canInviteForPerson($member, $person, PersonRelation::Own);

        if ($data['can_manage_minor_permissions']) {
            $data['minor_permissions'] = $this->minorPermissions($person);
        }

        $data['teams'] = $person->teamMemberships()->with('team')->get()
            ->filter(fn (TeamMembership $m) => $m->team->archived_at === null)
            ->map(fn (TeamMembership $m) => ['id' => $m->team_id, 'name' => $m->team->name, 'role' => $m->role->value])
            ->values()->all();

        return response()->json(['data' => $data]);
    }

    public function update(Request $request, Person $person): JsonResponse
    {
        $member = $this->current->get();
        $editable = $this->permissions->editableFields($member, $person);
        $canManage = $this->permissions->canManageProfile($member, $person);

        $rules = [
            'first_name' => ['sometimes', 'required', 'string', 'max:80'],
            'last_name' => ['sometimes', 'required', 'string', 'max:80'],
            'jersey_number' => ['sometimes', 'nullable', 'integer', 'between:0,99'],
            'birthday' => ['sometimes', 'nullable', 'date', 'before:today'],
            'gender' => ['sometimes', 'required', Rule::enum(Gender::class)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'address' => ['sometimes', 'nullable', 'array'],
            'address.street' => ['nullable', 'string', 'max:120'],
            'address.postal_code' => ['nullable', 'string', 'max:16'],
            'address.city' => ['nullable', 'string', 'max:80'],
            'address.country' => ['nullable', 'string', 'size:2'],
            'primary_position' => ['sometimes', 'nullable', Rule::enum(Position::class)],
            'secondary_positions' => ['sometimes', 'nullable', 'array'],
            'secondary_positions.*' => [Rule::enum(Position::class)],
            'height_cm' => ['sometimes', 'nullable', 'integer', 'between:100,250'],
            'reach_height_cm' => ['sometimes', 'nullable', 'integer', 'between:150,350'],
            'jump_reach_cm' => ['sometimes', 'nullable', 'integer', 'between:150,400'],
            'shared_fields' => ['sometimes', 'array'],
            'shared_fields.*' => [Rule::in(Person::DETAIL_FIELDS)],
        ];

        $sent = array_keys($request->only(array_merge(Person::EDITABLE_FIELDS, ['shared_fields'])));
        $forbidden = array_diff($sent, $editable, $canManage ? ['shared_fields'] : []);
        abort_if($forbidden !== [], 403, __('persons.field_not_editable'));

        $data = $request->validate($rules);

        if (array_key_exists('address', $data)) {
            foreach (Person::ADDRESS_COLUMNS as $column) {
                $data[$column] = $data['address'][$column] ?? null;
            }
            unset($data['address']);
        }
        if (array_key_exists('shared_fields', $data)) {
            $data['shared_fields'] = array_values(array_unique($data['shared_fields']));
        }

        $person->fill($data)->save();

        return $this->show($person->refresh());
    }

    public function uploadAvatar(Request $request, Person $person, ImageStore $images): JsonResponse
    {
        abort_unless($this->canChangeAvatar($person), 403);

        $request->validate(['image' => ['required', 'file', 'image', 'mimes:jpeg,png,webp,gif', 'max:8192']]);

        $previous = $person->avatar;
        $file = $images->store($request->file('image'), 'avatar', $this->current->get()->id);
        $person->forceFill(['avatar_file_id' => $file->id])->save();
        if ($previous !== null) {
            $images->delete($previous);
        }

        return $this->show($person->refresh());
    }

    public function deleteAvatar(Person $person, ImageStore $images): JsonResponse
    {
        abort_unless($this->canChangeAvatar($person), 403);

        $previous = $person->avatar;
        $person->forceFill(['avatar_file_id' => null])->save();
        if ($previous !== null) {
            $images->delete($previous);
        }

        return $this->show($person->refresh());
    }

    /** DSGVO: everything stored about the person as a JSON file. */
    public function export(Person $person, PersonData $data): JsonResponse
    {
        abort_unless($this->permissions->canManagePersonalData($this->current->get(), $person), 403);

        $name = str($person->first_name.' '.$person->last_name)->slug()->toString();

        return response()->json($data->export($person), 200, [
            'Content-Disposition' => 'attachment; filename="daten-'.$name.'.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** DSGVO: delete the profile and everything belonging to it (cannot be undone). */
    public function destroy(Person $person, PersonData $data): JsonResponse
    {
        abort_unless($this->permissions->canManagePersonalData($this->current->get(), $person), 403);

        $data->delete($person);

        return response()->json(['message' => 'ok']);
    }

    /** Referee license with expiry date; the person (parents for children) or admins. */
    public function updateRefereeLicense(Request $request, Person $person): JsonResponse
    {
        abort_unless($this->permissions->canEditRefereeLicense($this->current->get(), $person), 403);
        $data = $request->validate([
            'level' => ['required', 'string', 'max:20'],
            'license_number' => ['nullable', 'string', 'max:40'],
            'valid_until' => ['required', 'date'],
        ]);

        RefereeLicense::query()->updateOrCreate(['person_id' => $person->id], $data);

        return $this->show($person->refresh());
    }

    public function deleteRefereeLicense(Person $person): JsonResponse
    {
        abort_unless($this->permissions->canEditRefereeLicense($this->current->get(), $person), 403);

        RefereeLicense::query()->where('person_id', $person->id)->delete();

        return $this->show($person->refresh());
    }

    public function updateMinorPermissions(Request $request, Person $person): JsonResponse
    {
        $member = $this->current->get();
        abort_unless($this->permissions->canManageMinorPermissions($member, $person), 403);

        $data = $request->validate(array_fill_keys(MinorPermission::FLAGS, ['sometimes', 'boolean']) + [
            'editable_profile_fields' => ['sometimes', 'array'],
            'editable_profile_fields.*' => [Rule::in([...Person::EDITABLE_FIELDS, 'avatar'])],
        ]);

        $permission = MinorPermission::query()->firstOrCreate(['person_id' => $person->id]);
        $permission->fill($data)->forceFill(['updated_by' => $member->id])->save();

        return response()->json(['data' => $this->minorPermissions($person)]);
    }

    private function canChangeAvatar(Person $person): bool
    {
        $member = $this->current->get();

        return $this->permissions->canManageProfile($member, $person)
            || in_array('avatar', $person->minorPermission?->editable_profile_fields ?? [], true)
                && $this->permissions->actsFor($member, $person);
    }

    /**
     * @return array<string, mixed>
     */
    private function minorPermissions(Person $person): array
    {
        $permission = MinorPermission::query()->firstOrNew(['person_id' => $person->id]);

        return Arr::only($permission->toArray(), MinorPermission::FLAGS) + [
            'editable_profile_fields' => $permission->editable_profile_fields ?? [],
        ];
    }

    private function isAnyTrainer(): bool
    {
        return $this->permissions->ownMemberships($this->current->get())
            ->contains(fn (TeamMembership $m) => $m->role === TeamRole::Trainer);
    }
}
