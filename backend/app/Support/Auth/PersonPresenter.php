<?php

namespace App\Support\Auth;

use App\Models\Person;
use App\Models\TenantMember;
use App\Support\Tenancy\TenantContext;

/**
 * Turns a person into API data for a given viewer: standard fields for
 * every member, detail fields only if shared or the viewer may see them
 * anyway (self, parents, trainers of the person's teams, admins).
 */
class PersonPresenter
{
    public function __construct(private Permissions $permissions, private TenantContext $tenants) {}

    /**
     * @return array<string, mixed>
     */
    public function present(Person $person, TenantMember $viewer): array
    {
        $all = $this->permissions->canSeeAllDetails($viewer, $person);
        $visible = fn (string $field) => $all || $person->sharesField($field);

        return [
            'id' => $person->id,
            'first_name' => $person->first_name,
            'last_name' => $person->last_name,
            'jersey_number' => $person->jersey_number,
            'gender' => $person->gender?->value,
            'primary_position' => $person->primary_position?->value,
            'secondary_positions' => $person->secondary_positions ?? [],
            'height_cm' => $person->height_cm,
            'reach_height_cm' => $person->reach_height_cm,
            'jump_reach_cm' => $person->jump_reach_cm,
            'avatar_url' => $person->avatar_file_id
                ? '/api/v1/t/'.$this->tenants->get()->slug.'/files/'.$person->avatar_file_id
                : null,
            'phone' => $visible('phone') ? $person->phone : null,
            'email' => $visible('email') ? $person->email : null,
            'address' => $visible('address') ? [
                'street' => $person->street,
                'postal_code' => $person->postal_code,
                'city' => $person->city,
                'country' => $person->country,
            ] : null,
            'birthday' => $visible('birthday') ? $person->birthday?->toDateString() : null,
            'is_minor' => $person->hasGuardians(),
        ];
    }

    /**
     * Full view for the profile page: adds what the viewer may change.
     *
     * @return array<string, mixed>
     */
    public function presentForEditing(Person $person, TenantMember $viewer): array
    {
        $canManage = $this->permissions->canManageProfile($viewer, $person);

        return $this->present($person, $viewer) + [
            'shared_fields' => $canManage ? ($person->shared_fields ?? []) : null,
            'editable_fields' => $this->permissions->editableFields($viewer, $person),
            'can_manage_profile' => $canManage,
            'can_manage_minor_permissions' => $this->permissions->canManageMinorPermissions($viewer, $person),
        ];
    }
}
