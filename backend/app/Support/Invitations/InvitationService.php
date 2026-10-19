<?php

namespace App\Support\Invitations;

use App\Enums\InvitationKind;
use App\Enums\PersonRelation;
use App\Enums\TeamRole;
use App\Models\Invitation;
use App\Models\MinorPermission;
use App\Models\Person;
use App\Models\Team;
use App\Models\TenantMember;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class InvitationService
{
    public function createTeamLink(Team $team, TeamRole $role, TenantMember $creator, ?int $expiresInDays, ?int $maxUses): Invitation
    {
        return $this->create([
            'kind' => InvitationKind::Team,
            'team_id' => $team->id,
            'role' => $role,
            'max_uses' => $maxUses,
            'expires_at' => $expiresInDays ? Carbon::now()->addDays($expiresInDays) : null,
            'created_by' => $creator->id,
        ]);
    }

    /** Link into the club without a team – e.g. for further admins. */
    public function createDepartmentLink(bool $admin, TenantMember $creator, ?int $expiresInDays, ?int $maxUses): Invitation
    {
        return $this->create([
            'kind' => InvitationKind::Department,
            'grants_admin' => $admin,
            'max_uses' => $maxUses,
            'expires_at' => $expiresInDays ? Carbon::now()->addDays($expiresInDays) : null,
            'created_by' => $creator->id,
        ]);
    }

    /**
     * Single-use link that connects an account to an existing profile: a
     * parent to a child (guardian) or someone to their own profile (self).
     */
    public function createPersonLink(Person $person, PersonRelation $relation, TenantMember $creator): Invitation
    {
        if ($relation === PersonRelation::Own && $person->hasGuardians()) {
            // Parents invite their child to its own login – that is the permission.
            MinorPermission::query()->firstOrCreate(['person_id' => $person->id])
                ->forceFill(['own_login' => true, 'updated_by' => $creator->id])->save();
        }

        return $this->create([
            'kind' => InvitationKind::Person,
            'person_id' => $person->id,
            'relation' => $relation,
            'max_uses' => 1,
            'expires_at' => Carbon::now()->addDays(14),
            'created_by' => $creator->id,
        ]);
    }

    public function findUsable(string $token): ?Invitation
    {
        $invitation = Invitation::query()->where('token_hash', hash('sha256', $token))->first();

        return $invitation?->isUsable() ? $invitation : null;
    }

    public function revoke(Invitation $invitation): void
    {
        $invitation->forceFill(['revoked_at' => Carbon::now()])->save();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function create(array $attributes): Invitation
    {
        $token = Str::random(32);

        return Invitation::create($attributes + [
            'token_hash' => hash('sha256', $token),
            'token_encrypted' => $token,
        ]);
    }
}
