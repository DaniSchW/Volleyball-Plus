<?php

namespace App\Support\Privacy;

use App\Enums\MemberStatus;
use App\Enums\PersonRelation;
use App\Models\CashEntry;
use App\Models\ChatMessage;
use App\Models\CrossNomination;
use App\Models\Event;
use App\Models\EventInvitee;
use App\Models\EventResponse;
use App\Models\EventResponseLog;
use App\Models\Invitation;
use App\Models\InvitationUse;
use App\Models\MinorPermission;
use App\Models\Person;
use App\Models\PersonAccount;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\PollVote;
use App\Models\RefereeLicense;
use App\Models\RsvpRule;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TeamMembership;
use App\Models\TenantMember;
use App\Models\TrainingGroupMember;
use App\Support\Files\ImageStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DSGVO: everything stored about a person (export) and deleting it.
 *
 * Deleting removes the profile and everything that belongs to it (team
 * memberships, replies, tasks, votes, licence, picture). What other people
 * need stays without the person: cash bookings (no longer linked), chat
 * messages (author shown as deleted). An own login of the person is
 * disabled in this department.
 */
class PersonData
{
    public function __construct(private ImageStore $images) {}

    /** @return array<string, mixed> */
    public function export(Person $person): array
    {
        $person->loadMissing(['refereeLicense', 'minorPermission']);
        $links = PersonAccount::query()->with('member.account')->where('person_id', $person->id)->get();
        $ownMember = $links->first(fn (PersonAccount $l) => $l->relation === PersonRelation::Own && $l->ended_at === null)?->member;

        $events = fn ($ids) => Event::query()->whereIn('id', $ids)->get()->keyBy('id');
        $responses = EventResponse::query()->where('person_id', $person->id)->get();
        $eventById = $events($responses->pluck('event_id'));
        $assignments = TaskAssignment::query()->where('person_id', $person->id)->get();
        $tasks = Task::query()->whereIn('id', $assignments->pluck('task_id'))->get()->keyBy('id');
        $votes = PollVote::query()->where('person_id', $person->id)->get();
        $polls = Poll::query()->whereIn('id', $votes->pluck('poll_id'))->get()->keyBy('id');
        $options = PollOption::query()->whereIn('id', $votes->pluck('option_id')->filter())->get()->keyBy('id');

        return [
            'exported_at' => Carbon::now()->toIso8601String(),
            'profile' => collect($person->getAttributes())
                ->except(['tenant_id', 'avatar_file_id', 'deleted_at'])
                ->merge([
                    'secondary_positions' => $person->secondary_positions ?? [],
                    'shared_fields' => $person->shared_fields ?? [],
                    'has_profile_picture' => $person->avatar_file_id !== null,
                ])->all(),
            'logins' => $links->map(fn (PersonAccount $l) => [
                'relation' => $l->relation->value,
                // E-mail addresses of other people (e.g. the parents) are not part of the export.
                'email' => $l->relation === PersonRelation::Own ? $l->member?->account?->email : null,
                'since' => $l->created_at?->toIso8601String(),
                'ended_at' => $l->ended_at?->toIso8601String(),
            ])->values()->all(),
            'child_permissions' => $person->minorPermission?->only(['own_login', 'can_rsvp', 'can_chat', 'can_take_tasks', 'can_vote_polls', 'editable_profile_fields']),
            'referee_license' => $person->refereeLicense?->only(['level', 'license_number', 'valid_until']),
            'teams' => TeamMembership::query()->with('team')->where('person_id', $person->id)->get()->map(fn (TeamMembership $m) => [
                'team' => $m->team?->name, 'role' => $m->role->value, 'joined_at' => $m->joined_at?->toDateString(),
            ])->values()->all(),
            'training_groups' => TrainingGroupMember::query()->with('group.team')->where('person_id', $person->id)->get()
                ->map(fn (TrainingGroupMember $g) => ['team' => $g->group?->team?->name, 'group' => $g->group?->name])->values()->all(),
            'replies' => $responses->sortBy(fn (EventResponse $r) => $eventById[$r->event_id]?->starts_at)->map(fn (EventResponse $r) => [
                'event' => $eventById[$r->event_id]?->title,
                'starts_at' => $eventById[$r->event_id]?->starts_at?->toIso8601String(),
                'status' => $r->status->value,
                'position' => $r->as_position,
                'reason' => $r->reason,
                'responded_at' => $r->responded_at?->toIso8601String(),
            ])->values()->all(),
            'tasks' => $assignments->map(fn (TaskAssignment $a) => [
                'task' => $tasks[$a->task_id]?->title, 'taken_at' => $a->created_at?->toIso8601String(),
            ])->values()->all(),
            'poll_votes' => $votes->map(fn (PollVote $v) => [
                'poll' => $polls[$v->poll_id]?->title,
                'option' => $v->option_id ? $options[$v->option_id]?->label : null,
                'text' => $v->free_text,
            ])->values()->all(),
            'cash_bookings' => CashEntry::query()->where('person_id', $person->id)->get()->map(fn (CashEntry $e) => [
                'date' => $e->booked_on->toDateString(), 'amount_cents' => $e->amount_cents, 'category' => $e->category,
                'description' => $e->description, 'cancelled' => $e->cancelled_at !== null,
            ])->values()->all(),
            'chat_messages' => $ownMember === null ? [] : ChatMessage::query()->with('chat')->where('author_id', $ownMember->id)->whereNull('deleted_at')
                ->orderBy('id')->limit(10000)->get()->map(fn (ChatMessage $m) => [
                    'chat' => $m->chat?->displayName(), 'sent_at' => $m->created_at?->toIso8601String(), 'text' => $m->body,
                ])->values()->all(),
        ];
    }

    /** Refuses to remove the last admin. */
    public function delete(Person $person): void
    {
        $own = PersonAccount::query()->where('person_id', $person->id)->where('relation', PersonRelation::Own)->whereNull('ended_at')->first();
        $member = $own ? TenantMember::query()->find($own->tenant_member_id) : null;
        if ($member?->is_admin && TenantMember::query()->where('is_admin', true)->where('status', MemberStatus::Active)->count() <= 1) {
            throw ValidationException::withMessages(['person' => __('persons.last_admin')]);
        }

        DB::transaction(function () use ($person, $member) {
            // Memberships one by one: their events lose the person as invitee.
            TeamMembership::query()->where('person_id', $person->id)->get()->each->delete();
            CrossNomination::query()->where('person_id', $person->id)->get()->each->delete();
            TrainingGroupMember::query()->where('person_id', $person->id)->delete();
            EventResponseLog::query()->where('person_id', $person->id)->delete();
            EventResponse::query()->where('person_id', $person->id)->delete();
            EventInvitee::query()->where('person_id', $person->id)->delete();
            TaskAssignment::query()->where('person_id', $person->id)->delete();
            PollVote::query()->where('person_id', $person->id)->delete();
            RsvpRule::query()->where('person_id', $person->id)->delete();
            RefereeLicense::query()->where('person_id', $person->id)->delete();
            MinorPermission::query()->where('person_id', $person->id)->delete();
            InvitationUse::query()->where('person_id', $person->id)->update(['person_id' => null]);
            Invitation::query()->where('person_id', $person->id)->delete();
            CashEntry::query()->where('person_id', $person->id)->update(['person_id' => null]);
            PersonAccount::query()->where('person_id', $person->id)->delete();

            // The own login loses access; its links as parent stay so children stay protected.
            $member?->forceFill(['status' => MemberStatus::Disabled, 'is_admin' => false])->save();

            $avatar = $person->avatar;
            $person->forceFill([
                'first_name' => __('persons.deleted_first_name'), 'last_name' => __('persons.deleted_last_name'),
                'jersey_number' => null, 'birthday' => null, 'phone' => null, 'email' => null, 'street' => null,
                'postal_code' => null, 'city' => null, 'country' => null, 'primary_position' => null, 'secondary_positions' => null,
                'height_cm' => null, 'reach_height_cm' => null, 'jump_reach_cm' => null, 'avatar_file_id' => null, 'shared_fields' => null,
            ])->save();
            $person->delete();
            if ($avatar !== null) {
                $this->images->delete($avatar);
            }
        });
    }
}
