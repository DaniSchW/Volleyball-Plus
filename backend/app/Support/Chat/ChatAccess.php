<?php

namespace App\Support\Chat;

use App\Enums\MemberStatus;
use App\Enums\PersonRelation;
use App\Enums\TeamRole;
use App\Models\Chat;
use App\Models\ChatMember;
use App\Models\PersonAccount;
use App\Models\TeamMembership;
use App\Models\TenantMember;
use App\Support\Auth\Permissions;
use Illuminate\Support\Collection;

/**
 * Who reads and writes in which chat.
 *
 * Team chat: the logins behind the team's people – own logins (children
 * only with the parents' "chat" permission) and the parents of children.
 * Custom chat: the chosen members. Admins can open every chat.
 */
class ChatAccess
{
    public function __construct(private Permissions $permissions) {}

    /**
     * @return Collection<int, TenantMember>
     */
    public function readers(Chat $chat): Collection
    {
        return $chat->kind === Chat::KIND_TEAM
            ? $this->teamReaders($chat->team_id)
            : TenantMember::query()->whereIn('id', ChatMember::query()->where('chat_id', $chat->id)->select('tenant_member_id'))
                ->where('status', MemberStatus::Active)->get();
    }

    public function canRead(TenantMember $member, Chat $chat): bool
    {
        return $this->permissions->isAdmin($member) || $this->readers($chat)->contains('id', $member->id);
    }

    /** Topics, mode, deleting others' messages; for custom chats also the members. */
    public function canModerate(TenantMember $member, Chat $chat): bool
    {
        if ($this->permissions->isAdmin($member)) {
            return true;
        }
        if ($chat->kind === Chat::KIND_TEAM) {
            return $chat->team !== null && $this->permissions->canManageEventsOf($member, $chat->team);
        }

        return $chat->created_by === $member->id
            || ChatMember::query()->where('chat_id', $chat->id)->where('tenant_member_id', $member->id)->where('role', 'moderator')->exists();
    }

    /**
     * Team ids whose chats the member sees in the list (admins: all).
     *
     * @return Collection<int, string>
     */
    public function teamIdsFor(TenantMember $member): Collection
    {
        $own = TeamMembership::query()->whereIn('person_id', $this->permissions->actingPersonIds($member))->pluck('team_id');

        return $own->merge($this->permissions->ownMemberships($member)->whereIn('role', [TeamRole::Trainer, TeamRole::Organizer])->pluck('team_id'))
            ->unique()->values();
    }

    /**
     * @return Collection<int, TenantMember>
     */
    private function teamReaders(?string $teamId): Collection
    {
        $personIds = TeamMembership::query()->where('team_id', $teamId)->distinct()->pluck('person_id');
        $links = PersonAccount::query()->whereIn('person_id', $personIds)->whereNull('ended_at')
            ->with(['member', 'person.minorPermission'])->get();
        $children = $links->where('relation', PersonRelation::Guardian)->pluck('person_id')->flip();

        return $links
            ->filter(fn (PersonAccount $link) => $link->relation === PersonRelation::Guardian
                || ! $children->has($link->person_id)
                || ((bool) $link->person?->minorPermission?->own_login && (bool) $link->person?->minorPermission?->can_chat))
            ->map(fn (PersonAccount $link) => $link->member)
            ->filter(fn (?TenantMember $m) => $m !== null && $m->status === MemberStatus::Active)
            ->unique('id')->values();
    }
}
