<?php

namespace App\Http\Controllers\Api;

use App\Enums\Gender;
use App\Enums\InvitationKind;
use App\Enums\PersonRelation;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\Person;
use App\Models\Team;
use App\Models\TenantMember;
use App\Support\Auth\CurrentMember;
use App\Support\Auth\Permissions;
use App\Support\Invitations\InvitationService;
use App\Support\Invitations\JoinService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

class InvitationController extends Controller
{
    public function __construct(private InvitationService $invitations, private Permissions $permissions) {}

    // ---- managing links (members) ----

    public function index(Team $team, CurrentMember $current): JsonResponse
    {
        abort_unless($this->permissions->canInviteToTeam($current->get(), $team), 403);

        $links = Invitation::query()
            ->where('team_id', $team->id)
            ->whereNull('revoked_at')
            ->latest()
            ->get()
            ->filter(fn (Invitation $i) => $i->isUsable());

        return response()->json(['data' => $links->map(fn (Invitation $i) => $this->present($i))->values()->all()]);
    }

    public function store(Request $request, Team $team, CurrentMember $current): JsonResponse
    {
        $member = $current->get();
        abort_unless($this->permissions->canInviteToTeam($member, $team), 403);

        $data = $request->validate([
            'role' => ['required', Rule::enum(TeamRole::class)],
            'expires_in_days' => ['nullable', 'integer', 'between:1,365'],
            'max_uses' => ['nullable', 'integer', 'between:1,500'],
        ]);
        $role = TeamRole::from($data['role']);

        // Organizers may only invite players; staff roles need a trainer or admin.
        abort_if($role !== TeamRole::Player && ! $this->permissions->canManageTeam($member, $team), 403);

        $invitation = $this->invitations->createTeamLink($team, $role, $member, $data['expires_in_days'] ?? null, $data['max_uses'] ?? null);

        return response()->json(['data' => $this->present($invitation)], 201);
    }

    /**
     * Single-use link for an existing profile: a parent for a child, the
     * child's own login, or an adult whose profile was created by a trainer.
     */
    public function storeForPerson(Request $request, Person $person, CurrentMember $current): JsonResponse
    {
        $member = $current->get();
        $data = $request->validate(['relation' => ['required', Rule::in(['self', 'guardian'])]]);
        $relation = PersonRelation::from($data['relation']);

        abort_unless($this->permissions->canInviteForPerson($member, $person, $relation), 403);

        $invitation = $this->invitations->createPersonLink($person, $relation, $member);

        return response()->json(['data' => $this->present($invitation)], 201);
    }

    public function destroy(Invitation $invitation, CurrentMember $current): JsonResponse
    {
        $member = $current->get();
        $allowed = $invitation->kind === InvitationKind::Team
            ? $this->permissions->canInviteToTeam($member, $invitation->team)
            : ($this->permissions->isAdmin($member) || $invitation->created_by === $member->id);
        abort_unless($allowed, 403);

        $this->invitations->revoke($invitation);

        return response()->json(['message' => 'ok']);
    }

    // ---- public: opening and accepting a link ----

    public function showPublic(string $token, TenantContext $tenants): JsonResponse
    {
        $invitation = $this->invitations->findUsable($token);
        abort_if($invitation === null, 404, __('invitations.invalid'));

        return response()->json(['data' => [
            'tenant' => $tenants->get()->name,
            'kind' => $invitation->kind->value,
            'team' => $invitation->team?->name,
            'role' => $invitation->role?->value,
            'relation' => $invitation->relation === PersonRelation::Own ? 'self' : $invitation->relation?->value,
            'person' => $invitation->person ? $invitation->person->first_name.' '.$invitation->person->last_name : null,
            'allows_child' => $invitation->kind === InvitationKind::Team && $invitation->role === TeamRole::Player,
        ]]);
    }

    public function accept(Request $request, string $token, JoinService $joins): JsonResponse
    {
        abort_unless(RateLimiter::attempt('join-ip:'.$request->ip(), 20, fn () => true, 15 * 60), 429, __('auth.too_many_requests'));

        $invitation = $this->invitations->findUsable($token);
        abort_if($invitation === null, 404, __('invitations.invalid'));

        $loggedIn = $request->user() !== null;
        $isTeam = $invitation->kind === InvitationKind::Team;
        $child = $isTeam && $request->input('mode') === 'child';
        $latestChildBirthday = Carbon::today()->subDay()->toDateString();
        $earliestChildBirthday = Carbon::today()->subYears(18)->addDay()->toDateString();

        $data = $request->validate([
            'email' => [$loggedIn ? 'prohibited' : 'required', 'email', 'max:255'],
            'mode' => [$isTeam ? 'required' : 'prohibited', Rule::in($invitation->role === TeamRole::Player ? ['self', 'child'] : ['self'])],
            // Own profile – only used if the account has none in this department yet.
            'first_name' => [$isTeam && ! $child && ! $loggedIn ? 'required' : 'nullable', 'string', 'max:80'],
            'last_name' => [$isTeam && ! $child && ! $loggedIn ? 'required' : 'nullable', 'string', 'max:80'],
            'gender' => [$isTeam && ! $child && ! $loggedIn ? 'required' : 'nullable', Rule::enum(Gender::class)],
            'birthday' => ['nullable', 'date', 'before:today'],
            'child_first_name' => [$child ? 'required' : 'prohibited', 'string', 'max:80'],
            'child_last_name' => [$child ? 'required' : 'prohibited', 'string', 'max:80'],
            'child_gender' => [$child ? 'required' : 'prohibited', Rule::enum(Gender::class)],
            'child_birthday' => [$child ? 'required' : 'prohibited', 'date', 'before_or_equal:'.$latestChildBirthday, 'after_or_equal:'.$earliestChildBirthday],
        ], [
            'child_birthday.after_or_equal' => __('invitations.child_must_be_minor'),
        ]);

        if ($loggedIn && $isTeam && ! $child && $this->needsOwnProfileData($request)) {
            $request->validate([
                'first_name' => ['required'], 'last_name' => ['required'], 'gender' => ['required'],
            ]);
        }

        $result = $joins->request($invitation, $data, $request);

        return response()->json([
            'data' => ['status' => $result],
            'message' => $result === 'confirm' ? __('invitations.confirm_mail_sent') : __('invitations.joined'),
        ], $result === 'confirm' ? 202 : 200);
    }

    private function needsOwnProfileData(Request $request): bool
    {
        $member = TenantMember::query()->where('account_id', $request->user()->id)->first();

        return $member === null || $member->ownPerson() === null;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Invitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'kind' => $invitation->kind->value,
            'role' => $invitation->role?->value,
            'relation' => $invitation->relation === PersonRelation::Own ? 'self' : $invitation->relation?->value,
            'url' => $invitation->url(),
            'use_count' => $invitation->use_count,
            'max_uses' => $invitation->max_uses,
            'expires_at' => $invitation->expires_at?->toIso8601String(),
            'created_at' => $invitation->created_at?->toIso8601String(),
        ];
    }
}
