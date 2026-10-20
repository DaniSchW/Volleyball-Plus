<?php

namespace App\Support\Auth;

use App\Enums\EventScope;
use App\Enums\PersonRelation;
use App\Enums\TeamRole;
use App\Models\Document;
use App\Models\Event;
use App\Models\Person;
use App\Models\PersonAccount;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\TenantMember;
use Illuminate\Support\Collection;

/**
 * Central rule book: who may see and do what. Controllers and policies ask
 * here; nothing is decided in the frontend.
 *
 * - Admin: everything in the department.
 * - Trainer (per team): manage team, roster, invitations, settings; sees
 *   profile details of the team's people.
 * - Organizer (per team): organisation and invitations, no statistics.
 * - Guardian: acts for the child and alone decides over its profile.
 */
class Permissions
{
    /** @var array<string, Collection<int, PersonAccount>> */
    private array $links = [];

    /** @var array<string, Collection<int, TeamMembership>> */
    private array $memberships = [];

    /** @var array<string, bool> */
    private array $trainerOfPerson = [];

    public function isAdmin(TenantMember $member): bool
    {
        return $member->is_admin;
    }

    /**
     * Active links of the member to persons (own profile and children).
     *
     * @return Collection<int, PersonAccount>
     */
    public function links(TenantMember $member): Collection
    {
        return $this->links[$member->id] ??= PersonAccount::query()
            ->where('tenant_member_id', $member->id)
            ->whereNull('ended_at')
            ->get();
    }

    /** @return list<string> */
    public function actingPersonIds(TenantMember $member): array
    {
        return $this->links($member)->pluck('person_id')->values()->all();
    }

    public function ownPersonId(TenantMember $member): ?string
    {
        return $this->links($member)->firstWhere('relation', PersonRelation::Own)?->person_id;
    }

    public function isGuardianOf(TenantMember $member, Person $person): bool
    {
        return $this->links($member)
            ->contains(fn (PersonAccount $link) => $link->person_id === $person->id && $link->relation === PersonRelation::Guardian);
    }

    public function actsFor(TenantMember $member, Person $person): bool
    {
        return in_array($person->id, $this->actingPersonIds($member), true);
    }

    /**
     * Team memberships of the member's own profile (roles are personal, a
     * parent does not become trainer through the child).
     *
     * @return Collection<int, TeamMembership>
     */
    public function ownMemberships(TenantMember $member): Collection
    {
        $own = $this->ownPersonId($member);

        return $this->memberships[$member->id] ??= $own === null
            ? new Collection
            : TeamMembership::query()->where('person_id', $own)->get();
    }

    public function hasRole(TenantMember $member, Team $team, TeamRole $role): bool
    {
        return $this->ownMemberships($member)
            ->contains(fn (TeamMembership $m) => $m->team_id === $team->id && $m->role === $role);
    }

    public function isTrainerOf(TenantMember $member, Team $team): bool
    {
        return $this->hasRole($member, $team, TeamRole::Trainer);
    }

    /** Settings, roster roles and team data. */
    public function canManageTeam(TenantMember $member, Team $team): bool
    {
        return $this->isAdmin($member) || $this->isTrainerOf($member, $team);
    }

    public function canInviteToTeam(TenantMember $member, Team $team): bool
    {
        return $this->canManageTeam($member, $team) || $this->hasRole($member, $team, TeamRole::Organizer);
    }

    /** Team members (incl. parents of child members) see the roster. */
    public function canSeeTeam(TenantMember $member, Team $team): bool
    {
        if ($this->isAdmin($member)) {
            return true;
        }

        return TeamMembership::query()
            ->where('team_id', $team->id)
            ->whereIn('person_id', $this->actingPersonIds($member))
            ->exists();
    }

    /** Trainer of at least one team the person belongs to. */
    public function isTrainerOfPerson(TenantMember $member, Person $person): bool
    {
        return $this->trainerOfPerson[$member->id.':'.$person->id] ??= $this->checkTrainerOfPerson($member, $person);
    }

    private function checkTrainerOfPerson(TenantMember $member, Person $person): bool
    {
        $trainerTeams = $this->ownMemberships($member)
            ->filter(fn (TeamMembership $m) => $m->role === TeamRole::Trainer)
            ->pluck('team_id');

        return $trainerTeams->isNotEmpty() && TeamMembership::query()
            ->where('person_id', $person->id)
            ->whereIn('team_id', $trainerTeams)
            ->exists();
    }

    /**
     * May see all detail fields (phone, e-mail, address, birthday) even if
     * the person did not share them.
     */
    public function canSeeAllDetails(TenantMember $member, Person $person): bool
    {
        return $this->isAdmin($member)
            || $this->actsFor($member, $person)
            || $this->isTrainerOfPerson($member, $person);
    }

    /**
     * Profile fields this member may change on the person.
     *
     * @return list<string>
     */
    public function editableFields(TenantMember $member, Person $person): array
    {
        if ($this->isAdmin($member) || $this->isGuardianOf($member, $person)) {
            return Person::EDITABLE_FIELDS;
        }

        if ($this->actsFor($member, $person)) {
            // A child edits only what the parents allowed.
            if ($person->hasGuardians()) {
                return array_values(array_intersect(Person::EDITABLE_FIELDS, $person->minorPermission?->editable_profile_fields ?? []));
            }

            return Person::EDITABLE_FIELDS;
        }

        if ($this->isTrainerOfPerson($member, $person)) {
            return ['jersey_number', 'primary_position', 'secondary_positions'];
        }

        return [];
    }

    /** Sharing settings, profile picture: the person itself or the parents. */
    public function canManageProfile(TenantMember $member, Person $person): bool
    {
        if ($this->isAdmin($member) || $this->isGuardianOf($member, $person)) {
            return true;
        }

        return $this->actsFor($member, $person) && ! $person->hasGuardians();
    }

    /**
     * DSGVO export and deleting the profile: the adult person, the parents
     * of a child, admins.
     */
    public function canManagePersonalData(TenantMember $member, Person $person): bool
    {
        return $this->canManageProfile($member, $person);
    }

    /** Only parents decide what the child may do. */
    public function canEditRefereeLicense(TenantMember $member, Person $person): bool
    {
        return $this->isAdmin($member) || $this->canManageProfile($member, $person);
    }

    public function canManageMinorPermissions(TenantMember $member, Person $person): bool
    {
        return $this->isGuardianOf($member, $person);
    }

    /**
     * A child's own login only works while the parents allow it.
     */
    public function mayUseApp(TenantMember $member): bool
    {
        $own = $this->ownPersonId($member);
        if ($own === null) {
            return true;
        }

        $person = Person::query()->with('minorPermission')->find($own);
        if ($person === null || ! $person->hasGuardians()) {
            return true;
        }

        return (bool) $person->minorPermission?->own_login;
    }

    /**
     * Single-use links for a profile: parents are invited by trainers/admins
     * (the second parent also by the first); an own login for a child only
     * by its parents, for an adult without login by trainers/admins.
     */
    public function canInviteForPerson(TenantMember $member, Person $person, PersonRelation $relation): bool
    {
        if ($relation === PersonRelation::Guardian) {
            return $this->isAdmin($member)
                || $this->isTrainerOfPerson($member, $person)
                || $this->isGuardianOf($member, $person);
        }

        $hasOwnAccount = PersonAccount::query()
            ->where('person_id', $person->id)
            ->where('relation', PersonRelation::Own)
            ->whereNull('ended_at')
            ->exists();
        if ($hasOwnAccount) {
            return false;
        }

        // A child's own login is the parents' decision alone.
        if ($person->hasGuardians()) {
            return $this->isGuardianOf($member, $person);
        }

        return $this->isAdmin($member) || $this->isTrainerOfPerson($member, $person);
    }

    // ---- documents ----

    /** Department documents: every member; team documents: who sees the team. */
    public function canSeeDocument(TenantMember $member, Document $document): bool
    {
        return $document->team_id === null || ($document->team !== null && $this->canSeeTeam($member, $document->team));
    }

    /** Department documents: admins; team documents: also the team's trainers and organizers. */
    public function canManageDocuments(TenantMember $member, ?Team $team): bool
    {
        return $team === null ? $this->isAdmin($member) : $this->canManageEventsOf($member, $team);
    }

    // ---- events ----

    /** Create and manage events of a team: admins, trainers and organizers. */
    public function canManageEventsOf(TenantMember $member, Team $team): bool
    {
        return $this->canInviteToTeam($member, $team);
    }

    /**
     * Department-wide events only by admins; team events by anybody who
     * manages events of one of its teams.
     */
    public function canManageEvent(TenantMember $member, Event $event): bool
    {
        if ($this->isAdmin($member)) {
            return true;
        }
        if ($event->scope === EventScope::Tenant) {
            return false;
        }

        return $event->teams->contains(fn (Team $team) => $this->canManageEventsOf($member, $team));
    }

    /**
     * Afterwards: record who was really there and correct replies of past
     * events (for the statistics) – admins and the teams' trainers.
     */
    public function canRecordAttendance(TenantMember $member, Event $event): bool
    {
        return $this->isAdmin($member) || $event->teams->contains(fn (Team $team) => $this->isTrainerOf($member, $team));
    }

    /**
     * Accept/decline for a person: for oneself, as parent for the child, or
     * as a child when the parents allow it.
     */
    public function canRespondFor(TenantMember $member, Person $person): bool
    {
        if (! $this->actsFor($member, $person)) {
            return false;
        }
        if ($this->isGuardianOf($member, $person) || ! $person->hasGuardians()) {
            return true;
        }

        return (bool) $person->minorPermission?->can_rsvp;
    }

    /** Taking tasks: parents for their child, the child itself only if allowed. */
    public function canTakeTasksFor(TenantMember $member, Person $person): bool
    {
        return $this->actsForWith($member, $person, 'can_take_tasks');
    }

    /** Voting in polls: parents for their child, the child itself only if allowed. */
    public function canVoteFor(TenantMember $member, Person $person): bool
    {
        return $this->actsForWith($member, $person, 'can_vote_polls');
    }

    // ---- cash & halls ----

    /** Team cash book: admins, trainers and organizers of the team. */
    public function canManageCashOf(TenantMember $member, Team $team): bool
    {
        return $this->canInviteToTeam($member, $team);
    }

    private function actsForWith(TenantMember $member, Person $person, string $flag): bool
    {
        if (! $this->actsFor($member, $person)) {
            return false;
        }
        if ($this->isGuardianOf($member, $person) || ! $person->hasGuardians()) {
            return true;
        }

        return (bool) $person->minorPermission?->{$flag};
    }
}
