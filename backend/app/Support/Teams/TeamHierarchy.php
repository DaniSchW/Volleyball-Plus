<?php

namespace App\Support\Teams;

use App\Enums\Gender;
use App\Enums\TeamGender;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamMembership;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Ranking of the department's teams from their league: a lower league
 * level is a higher team; two own teams in the same league are ordered by
 * rank_in_league. Youth teams and teams without league are outside the
 * hierarchy.
 *
 * "A player of a higher team may not play in a lower one" compares only
 * teams of the same kind (men, women, mixed), so a Herren 1 player may
 * still play in a mixed hobby team. Cross-nominations compare across kinds
 * (a mixed team may invite from lower men's and women's teams); the
 * gender rule applies on top.
 */
class TeamHierarchy
{
    /**
     * @return array{0: int, 1: int}|null
     */
    public function rank(Team $team): ?array
    {
        if ($team->is_youth || $team->league_id === null) {
            return null;
        }
        $team->loadMissing('league');

        return $team->league ? [$team->league->level, $team->rank_in_league] : null;
    }

    /** True if $a is above $b in the hierarchy. */
    public function isHigher(Team $a, Team $b, bool $sameKind = true): bool
    {
        $rankA = $this->rank($a);
        $rankB = $this->rank($b);
        if ($rankA === null || $rankB === null || $a->id === $b->id || ($sameKind && $a->gender !== $b->gender)) {
            return false;
        }

        return $rankA < $rankB;
    }

    /** Herren teams take men, Damen teams women, mixed teams both. */
    public function genderFits(Team $team, ?Gender $gender): bool
    {
        return match ($team->gender) {
            TeamGender::Men => $gender === Gender::Male,
            TeamGender::Women => $gender === Gender::Female,
            TeamGender::Mixed => true,
        };
    }

    /**
     * A player of a higher team may not also play in a lower one. Returns
     * the higher team that blocks joining $team as player, if any.
     */
    public function blockingTeam(Team $team, string $personId): ?Team
    {
        if ($this->rank($team) === null) {
            return null;
        }

        return $this->playerTeams($personId)->first(fn (Team $other) => $this->isHigher($other, $team));
    }

    /**
     * @throws ValidationException when a player of a higher team would join a lower one
     */
    public function assertMayJoin(Team $team, string $personId, TeamRole $role): void
    {
        if ($role !== TeamRole::Player) {
            return;
        }

        $higher = $this->blockingTeam($team, $personId);
        if ($higher !== null) {
            throw ValidationException::withMessages(['person_id' => __('teams.higher_team', ['team' => $higher->name, 'lower' => $team->name])]);
        }
    }

    /**
     * @return Collection<int, Team>
     */
    public function playerTeams(string $personId): Collection
    {
        return Team::query()
            ->with('league')
            ->whereNull('archived_at')
            ->whereIn('id', TeamMembership::query()->where('person_id', $personId)->where('role', TeamRole::Player)->select('team_id'))
            ->get();
    }

    /**
     * Sort key for lists: ranked teams first (by rank), then the others.
     *
     * @return array{0: int, 1: int, 2: int, 3: string}
     */
    public function sortKey(Team $team): array
    {
        $rank = $this->rank($team);

        return [$team->is_youth ? 2 : ($rank === null ? 1 : 0), $rank[0] ?? 0, $rank[1] ?? 0, mb_strtolower($team->name)];
    }
}
