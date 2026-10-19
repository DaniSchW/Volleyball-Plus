<?php

namespace App\Support\Invitations;

use App\Enums\AuthTokenPurpose;
use App\Enums\InvitationKind;
use App\Enums\MemberStatus;
use App\Enums\PersonRelation;
use App\Enums\TeamRole;
use App\Models\Account;
use App\Models\AuthToken;
use App\Models\Invitation;
use App\Models\InvitationUse;
use App\Models\MinorPermission;
use App\Models\Person;
use App\Models\PersonAccount;
use App\Models\TeamMembership;
use App\Models\TenantMember;
use App\Support\Auth\MagicLinks;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Joining via invitation link. Without login the e-mail address is first
 * confirmed by magic link; the actual join happens when that link is
 * redeemed. Logged-in members join directly.
 *
 * Modes for team links:
 * - self:  join with one's own profile (created if needed)
 * - child: a parent registers a minor child (only for player links)
 */
class JoinService
{
    public function __construct(private MagicLinks $links, private TenantContext $tenants) {}

    /**
     * @param  array<string, mixed>  $data  validated accept form
     * @return 'joined'|'confirm'
     */
    public function request(Invitation $invitation, array $data, Request $request): string
    {
        $account = $request->user();

        if ($account instanceof Account) {
            $this->complete($invitation, $account, $data);

            return 'joined';
        }

        $this->links->send(
            $this->tenants->get(),
            $data['email'],
            AuthTokenPurpose::Join,
            ['invitation_id' => $invitation->id, 'data' => array_diff_key($data, ['email' => true])],
            $request,
        );

        return 'confirm';
    }

    public function completeFromToken(AuthToken $token): Account
    {
        $invitation = Invitation::query()->find($token->payload['invitation_id'] ?? null);
        if ($invitation === null) {
            throw ValidationException::withMessages(['token' => __('invitations.invalid')]);
        }

        $account = Account::query()->firstOrCreate(['email' => $token->email]);

        $this->complete($invitation, $account, $token->payload['data'] ?? []);

        return $account;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function complete(Invitation $invitation, Account $account, array $data): TenantMember
    {
        return DB::transaction(function () use ($invitation, $account, $data) {
            // Lock so max_uses holds under simultaneous joins.
            $invitation = Invitation::query()->lockForUpdate()->findOrFail($invitation->id);
            if (! $invitation->isUsable()) {
                throw ValidationException::withMessages(['token' => __('invitations.invalid')]);
            }

            $member = TenantMember::query()->firstOrCreate(['account_id' => $account->id]);
            if ($member->status !== MemberStatus::Active) {
                $member->forceFill(['status' => MemberStatus::Active])->save();
            }

            $person = match ($invitation->kind) {
                InvitationKind::Team => $this->joinTeam($invitation, $member, $data),
                InvitationKind::Department => $this->ownProfile($member, $data),
                InvitationKind::Person => $this->connectPerson($invitation, $member),
            };
            if ($invitation->kind === InvitationKind::Department && $invitation->grants_admin && ! $member->is_admin) {
                $member->forceFill(['is_admin' => true])->save();
            }

            $invitation->increment('use_count');
            InvitationUse::create([
                'invitation_id' => $invitation->id,
                'tenant_member_id' => $member->id,
                'person_id' => $person->id,
            ]);

            return $member;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function joinTeam(Invitation $invitation, TenantMember $member, array $data): Person
    {
        if (($data['mode'] ?? 'self') === 'child') {
            if ($invitation->role !== TeamRole::Player) {
                throw ValidationException::withMessages(['mode' => __('invitations.child_only_players')]);
            }

            $child = Person::create([
                'first_name' => $data['child_first_name'],
                'last_name' => $data['child_last_name'],
                'gender' => $data['child_gender'],
                'birthday' => $data['child_birthday'],
            ]);
            PersonAccount::create(['person_id' => $child->id, 'tenant_member_id' => $member->id, 'relation' => PersonRelation::Guardian]);
            MinorPermission::create(['person_id' => $child->id]);

            $this->addToTeam($invitation, $child);

            return $child;
        }

        $person = $this->ownProfile($member, $data);
        $this->addToTeam($invitation, $person);

        return $person;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function ownProfile(TenantMember $member, array $data): Person
    {
        $person = $member->ownPerson();
        if ($person === null) {
            $person = Person::create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'gender' => $data['gender'],
                'birthday' => $data['birthday'] ?? null,
                'email' => $member->account->email,
            ]);
            PersonAccount::create(['person_id' => $person->id, 'tenant_member_id' => $member->id, 'relation' => PersonRelation::Own]);
        }

        return $person;
    }

    private function connectPerson(Invitation $invitation, TenantMember $member): Person
    {
        $person = $invitation->person;

        if ($invitation->relation === PersonRelation::Own) {
            $taken = PersonAccount::query()
                ->where('person_id', $person->id)
                ->where('relation', PersonRelation::Own)
                ->whereNull('ended_at')
                ->exists();
            // A member can have only one own profile per department.
            if ($taken || $member->ownPerson() !== null) {
                throw ValidationException::withMessages(['token' => __('invitations.profile_taken')]);
            }
        }

        $link = PersonAccount::query()->firstOrNew(['person_id' => $person->id, 'tenant_member_id' => $member->id]);
        $link->forceFill(['relation' => $invitation->relation, 'ended_at' => null])->save();

        if ($invitation->relation === PersonRelation::Guardian) {
            MinorPermission::query()->firstOrCreate(['person_id' => $person->id]);
        }

        return $person;
    }

    private function addToTeam(Invitation $invitation, Person $person): void
    {
        TeamMembership::query()->firstOrCreate(
            ['team_id' => $invitation->team_id, 'person_id' => $person->id, 'role' => $invitation->role],
            ['joined_at' => Carbon::today()],
        );
    }
}
