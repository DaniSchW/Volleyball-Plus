<?php

namespace App\Support\Polls;

use App\Enums\EventScope;
use App\Enums\NotificationCategory;
use App\Enums\TeamRole;
use App\Models\Person;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\PollTeam;
use App\Models\PollVote;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\TenantMember;
use App\Support\Auth\Permissions;
use App\Support\Notifications\Notifier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Polls of teams. Everybody in one of the poll's teams may vote (parents
 * for their children, children only if allowed). Results are shown once
 * someone has voted, when the poll is closed, and always to people who
 * manage it. Anonymous polls never show names.
 */
class PollService
{
    public function __construct(private Permissions $permissions, private Notifier $notifier) {}

    /**
     * @param  array<string, mixed>  $data  validated input
     * @param  Collection<int, Team>  $teams
     */
    public function create(array $data, Collection $teams, TenantMember $creator, bool $push = true, string $email = 'without_push'): Poll
    {
        $poll = DB::transaction(function () use ($data, $teams, $creator) {
            $poll = Poll::create([
                ...collect($data)->only(['title', 'description', 'scope', 'multiple_choice', 'anonymous', 'allow_free_text', 'closes_at'])->all(),
                'created_by' => $creator->id,
            ]);
            foreach ($teams as $team) {
                PollTeam::create(['poll_id' => $poll->id, 'team_id' => $team->id]);
            }
            foreach (array_values($data['options']) as $i => $label) {
                PollOption::create(['poll_id' => $poll->id, 'label' => $label, 'sort_order' => $i]);
            }

            return $poll;
        });

        $this->notifier->toPersonsDirect(NotificationCategory::Poll, $this->eligiblePersonIds($poll), fn (string $locale) => [
            'title' => __('notifications.poll.title', ['title' => $poll->title], $locale),
            'body' => Str::limit((string) $poll->description, 140),
        ], '/polls?poll='.$poll->id, $push, $email, $creator);

        return $poll;
    }

    /**
     * Reminder for a running poll – by default only to those who have not voted yet.
     *
     * @return array{push: int, email: int}
     */
    public function remind(Poll $poll, TenantMember $sender, bool $push, string $email, bool $onlyOpen): array
    {
        $persons = $this->eligiblePersonIds($poll);
        if ($onlyOpen) {
            $persons = $persons->diff(PollVote::query()->where('poll_id', $poll->id)->pluck('person_id'))->values();
        }
        $closes = $poll->closes_at;

        return $this->notifier->toPersonsDirect(NotificationCategory::Poll, $persons, fn (string $locale) => [
            'title' => __('notifications.poll.reminder', ['title' => $poll->title], $locale),
            'body' => $closes ? __('notifications.poll.until', ['date' => $this->notifier->when($closes, $locale)], $locale) : Str::limit((string) $poll->description, 140),
        ], '/polls?poll='.$poll->id, $push, $email, $sender);
    }

    /**
     * @param  list<string>  $optionIds
     */
    public function vote(Poll $poll, Person $person, array $optionIds, ?string $freeText, TenantMember $actor): void
    {
        if (! $this->permissions->canVoteFor($actor, $person)) {
            throw new AccessDeniedHttpException(__('polls.not_allowed'));
        }
        if (! $this->eligiblePersonIds($poll)->contains($person->id)) {
            throw ValidationException::withMessages(['person_id' => __('polls.not_eligible')]);
        }
        if ($poll->isClosed()) {
            throw ValidationException::withMessages(['poll' => __('polls.closed')]);
        }

        $optionIds = array_values(array_unique($optionIds));
        $freeText = $poll->allow_free_text ? (trim((string) $freeText) ?: null) : null;
        $valid = $poll->options()->pluck('id');
        if (collect($optionIds)->diff($valid)->isNotEmpty()) {
            throw ValidationException::withMessages(['option_ids' => __('polls.invalid_option')]);
        }
        if (! $poll->multiple_choice && count($optionIds) > 1) {
            throw ValidationException::withMessages(['option_ids' => __('polls.single_choice')]);
        }
        if ($optionIds === [] && $freeText === null) {
            throw ValidationException::withMessages(['option_ids' => __('polls.choose')]);
        }

        DB::transaction(function () use ($poll, $person, $optionIds, $freeText, $actor) {
            PollVote::query()->where('poll_id', $poll->id)->where('person_id', $person->id)->delete();
            foreach ($optionIds as $optionId) {
                PollVote::create(['poll_id' => $poll->id, 'option_id' => $optionId, 'person_id' => $person->id, 'voted_by' => $actor->id]);
            }
            if ($freeText !== null) {
                PollVote::create(['poll_id' => $poll->id, 'option_id' => null, 'person_id' => $person->id, 'voted_by' => $actor->id, 'free_text' => $freeText]);
            }
        });
    }

    public function withdraw(Poll $poll, Person $person, TenantMember $actor): void
    {
        if (! $this->permissions->canVoteFor($actor, $person)) {
            throw new AccessDeniedHttpException(__('polls.not_allowed'));
        }
        if ($poll->isClosed()) {
            throw ValidationException::withMessages(['poll' => __('polls.closed')]);
        }

        PollVote::query()->where('poll_id', $poll->id)->where('person_id', $person->id)->delete();
    }

    /**
     * Everybody in one of the poll's teams.
     *
     * @return Collection<int, string>
     */
    public function eligiblePersonIds(Poll $poll): Collection
    {
        return TeamMembership::query()
            ->whereIn('team_id', PollTeam::query()->where('poll_id', $poll->id)->select('team_id'))
            ->distinct()->pluck('person_id');
    }

    /** Admins; for team polls also trainers and organizers of the poll's teams. */
    public function canManage(TenantMember $member, Poll $poll): bool
    {
        if ($this->permissions->isAdmin($member)) {
            return true;
        }
        if ($poll->scope === EventScope::Tenant) {
            return false;
        }

        return $poll->created_by === $member->id
            || $poll->teams->contains(fn (Team $t) => $this->permissions->canManageEventsOf($member, $t));
    }

    public function canSee(TenantMember $member, Poll $poll): bool
    {
        return $this->canManage($member, $poll)
            || $this->eligiblePersonIds($poll)->intersect($this->permissions->actingPersonIds($member))->isNotEmpty();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Poll $poll, TenantMember $viewer): array
    {
        $poll->loadMissing(['teams', 'options']);
        $votes = PollVote::query()->where('poll_id', $poll->id)->get();
        $acting = Person::query()->with('minorPermission')->whereIn('id', $this->permissions->actingPersonIds($viewer))->get()->keyBy('id');
        $eligible = $this->eligiblePersonIds($poll);
        $canManage = $this->canManage($viewer, $poll);
        $mineVoted = $votes->whereIn('person_id', $acting->keys())->isNotEmpty();
        $resultsVisible = $canManage || $mineVoted || $poll->isClosed();
        $names = $resultsVisible && ! $poll->anonymous
            ? Person::query()->whereIn('id', $votes->pluck('person_id')->unique())->get()->mapWithKeys(fn (Person $p) => [$p->id => $p->first_name.' '.$p->last_name])
            : collect();

        return [
            'id' => $poll->id,
            'title' => $poll->title,
            'description' => $poll->description,
            'scope' => $poll->scope->value,
            'teams' => $poll->teams->map(fn (Team $t) => ['id' => $t->id, 'name' => $t->name])->values()->all(),
            'multiple_choice' => $poll->multiple_choice,
            'anonymous' => $poll->anonymous,
            'allow_free_text' => $poll->allow_free_text,
            'closes_at' => $poll->closes_at?->toIso8601ZuluString(),
            'is_closed' => $poll->isClosed(),
            'created_at' => $poll->created_at->toIso8601ZuluString(),
            'can_manage' => $canManage,
            'results_visible' => $resultsVisible,
            'voter_count' => $resultsVisible ? $votes->pluck('person_id')->unique()->count() : null,
            'eligible_count' => $eligible->count(),
            'options' => $poll->options->map(fn (PollOption $o) => [
                'id' => $o->id,
                'label' => $o->label,
                'count' => $resultsVisible ? $votes->where('option_id', $o->id)->count() : null,
                'voters' => $resultsVisible && ! $poll->anonymous ? $votes->where('option_id', $o->id)->map(fn ($v) => $names[$v->person_id] ?? '–')->values()->all() : null,
            ])->values()->all(),
            'free_texts' => $resultsVisible ? $votes->whereNull('option_id')->map(fn ($v) => [
                'text' => $v->free_text,
                'name' => $poll->anonymous ? null : ($names[$v->person_id] ?? null),
            ])->values()->all() : [],
            'my' => $acting->filter(fn (Person $p) => $eligible->contains($p->id))->map(fn (Person $p) => [
                'person_id' => $p->id,
                'name' => $p->first_name,
                'is_self' => $p->id === $this->permissions->ownPersonId($viewer),
                'option_ids' => $votes->where('person_id', $p->id)->whereNotNull('option_id')->pluck('option_id')->values()->all(),
                'free_text' => $votes->where('person_id', $p->id)->whereNull('option_id')->first()?->free_text,
                'can_vote' => ! $poll->isClosed() && $this->permissions->canVoteFor($viewer, $p),
            ])->values()->all(),
        ];
    }

    /**
     * Teams whose polls the member sees: own and children's teams, managed teams; admins all.
     *
     * @return Collection<int, string>
     */
    public function visibleTeamIds(TenantMember $member): Collection
    {
        if ($this->permissions->isAdmin($member)) {
            return Team::query()->pluck('id');
        }

        return TeamMembership::query()->whereIn('person_id', $this->permissions->actingPersonIds($member))->pluck('team_id')
            ->merge($this->permissions->ownMemberships($member)->whereIn('role', [TeamRole::Trainer, TeamRole::Organizer])->pluck('team_id'))
            ->unique()->values();
    }
}
