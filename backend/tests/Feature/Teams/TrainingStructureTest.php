<?php

namespace Tests\Feature\Teams;

use App\Enums\TeamRole;
use App\Models\EventInvitee;
use App\Models\League;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\Tenant;
use App\Models\TenantMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class TrainingStructureTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private TenantMember $admin;

    private TenantMember $trainer;

    private TenantMember $max;

    private TenantMember $paul;

    private TenantMember $lena;

    private TenantMember $ben;

    /** @var array<string, Team> */
    private array $teams = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-19 10:00:00');

        $this->tenant = $this->tenant();
        $this->admin = $this->member($this->tenant, 'ada@example.org', 'Ada', 'Admin', admin: true, gender: 'female');
        $leagues = collect($this->loginAs($this->admin)->getJson('/api/v1/t/ssv-ulm/leagues')->assertOk()->json('data'))->keyBy('name');

        $this->teams = $this->inTenant($this->tenant, fn () => [
            'H1' => Team::create(['name' => 'Herren 1', 'gender' => 'men', 'league_id' => $leagues['Regionalliga']['id']]),
            'H2' => Team::create(['name' => 'Herren 2', 'gender' => 'men', 'league_id' => $leagues['Bezirksliga']['id']]),
            'D1' => Team::create(['name' => 'Damen 1', 'gender' => 'women', 'league_id' => $leagues['Landesliga']['id']]),
            'MX' => Team::create(['name' => 'Mixed', 'gender' => 'mixed', 'league_id' => $leagues['Kreisliga']['id']]),
            'U18' => Team::create(['name' => 'U18 männlich', 'gender' => 'men', 'is_youth' => true]),
        ]);

        $this->trainer = $this->member($this->tenant, 'tom@example.org', 'Tom', 'Trainer');
        $this->max = $this->member($this->tenant, 'max@example.org', 'Max', 'Erster');
        $this->paul = $this->member($this->tenant, 'paul@example.org', 'Paul', 'Zweiter');
        $this->lena = $this->member($this->tenant, 'lena@example.org', 'Lena', 'Damen', gender: 'female');
        $this->ben = $this->member($this->tenant, 'ben@example.org', 'Ben', 'Jugend');
        $this->addToTeam($this->teams['H1'], $this->personOf($this->trainer), TeamRole::Trainer);
        $this->addToTeam($this->teams['H1'], $this->personOf($this->max));
        $this->addToTeam($this->teams['H2'], $this->personOf($this->paul));
        $this->addToTeam($this->teams['D1'], $this->personOf($this->lena));
        $this->addToTeam($this->teams['U18'], $this->personOf($this->ben));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function url(string $path): string
    {
        return '/api/v1/t/ssv-ulm/'.$path;
    }

    private function training(array $data = []): array
    {
        return $this->loginAs($this->trainer)->postJson($this->url('events'), $data + [
            'type' => 'training', 'team_ids' => [$this->teams['H1']->id],
            'starts_at' => '2026-10-22T19:30', 'ends_at' => '2026-10-22T21:30',
        ])->assertCreated()->json('data');
    }

    private function nominate(TenantMember $who, string $home, string $host = 'H1'): TestResponse
    {
        return $this->loginAs($this->trainer)->postJson($this->url("teams/{$this->teams[$host]->id}/cross-nominations"), [
            'person_id' => $this->personOf($who)->id, 'home_team_id' => $this->teams[$home]->id,
        ]);
    }

    private function invited(string $eventId): array
    {
        return $this->inTenant($this->tenant, fn () => EventInvitee::query()->where('event_id', $eventId)->pluck('source', 'person_id')->all());
    }

    private function respond(TenantMember $as, string $eventId, string $status): TestResponse
    {
        return $this->loginAs($as)->postJson($this->url("events/{$eventId}/responses"), [
            'person_id' => $this->personOf($as)->id, 'status' => $status, 'reason' => 'x',
        ]);
    }

    public function test_league_tree_is_prefilled_and_maintained_by_admins(): void
    {
        $tree = $this->loginAs($this->admin)->getJson($this->url('leagues'))->json('data');
        $this->assertSame(['1. Bundesliga', 1], [$tree[0]['name'], $tree[0]['level']]);
        $this->assertCount(11, $tree);
        $regional = collect($tree)->firstWhere('name', 'Regionalliga');
        $this->assertSame(1, $regional['team_count']);

        $tree = $this->loginAs($this->admin)->postJson($this->url('leagues'), ['name' => 'Regionalliga Süd', 'parent_id' => $regional['id']])->assertCreated()->json('data');
        $sub = collect($tree)->firstWhere('name', 'Regionalliga Süd');
        $this->assertSame([4, 1], [$sub['level'], $sub['depth']]);
        $this->assertSame('Regionalliga Süd', $tree[array_search('Regionalliga', array_column($tree, 'name')) + 1]['name']);

        $this->loginAs($this->trainer)->postJson($this->url('leagues'), ['name' => 'Hobbyliga'])->assertForbidden();
        $this->loginAs($this->admin)->deleteJson($this->url('leagues/'.$regional['id']))->assertUnprocessable();
        $this->loginAs($this->admin)->patchJson($this->url('leagues/'.$regional['id']), ['parent_id' => $sub['id']])->assertUnprocessable();
        $this->loginAs($this->admin)->deleteJson($this->url('leagues/'.$sub['id']))->assertOk();
        // Not seeded twice.
        $this->assertSame(11, $this->inTenant($this->tenant, fn () => League::count()));
    }

    public function test_teams_are_listed_in_hierarchy_order(): void
    {
        $names = array_column($this->loginAs($this->admin)->getJson($this->url('teams'))->json('data'), 'name');

        $this->assertSame(['Herren 1', 'Damen 1', 'Herren 2', 'Mixed', 'U18 männlich'], $names);
        $this->loginAs($this->admin)->getJson($this->url('teams/'.$this->teams['H2']->id))
            ->assertJsonPath('data.league.name', 'Bezirksliga')->assertJsonPath('data.in_hierarchy', true);
    }

    public function test_any_trainer_adds_players_of_the_department_who_appear_right_away(): void
    {
        $coach = $this->member($this->tenant, 'hanna@example.org', 'Hanna', 'Coach', gender: 'female');
        $this->addToTeam($this->teams['H2'], $this->personOf($coach), TeamRole::Trainer);

        $found = $this->loginAs($coach)->getJson($this->url('persons?q=Max'))->assertOk()->json('data.0');
        $this->assertSame([['id' => $this->teams['H1']->id, 'name' => 'Herren 1', 'role' => 'player']], $found['teams']);

        $this->loginAs($coach)->postJson($this->url("teams/{$this->teams['H2']->id}/members"), [
            'person_id' => $found['id'], 'role' => 'player',
        ])->assertCreated();
        $this->assertContains($found['id'], array_column(array_column(
            $this->loginAs($coach)->getJson($this->url("teams/{$this->teams['H2']->id}/members"))->json('data'), 'person'), 'id'));

        // Players cannot add people to teams.
        $this->loginAs($this->paul)->getJson($this->url('persons?q=Max'))->assertForbidden();
    }

    public function test_players_of_a_higher_team_train_with_a_lower_one_but_do_not_play_its_matches(): void
    {
        $max = $this->personOf($this->max)->id;
        $coach = $this->member($this->tenant, 'hanna@example.org', 'Hanna', 'Coach', gender: 'female');
        $this->addToTeam($this->teams['H2'], $this->personOf($coach), TeamRole::Trainer);
        $create = fn (string $team, string $type) => $this->loginAs($this->admin)->postJson($this->url('events'), [
            'type' => $type, 'team_ids' => [$this->teams[$team]->id], 'starts_at' => '2026-10-24T15:00', 'ends_at' => '2026-10-24T18:00',
        ])->assertCreated()->json('data.id');

        $training = $create('H2', 'training');
        $match = $create('H2', 'match');
        $mixedMatch = $create('MX', 'match');
        $this->loginAs($this->admin)->postJson($this->url("teams/{$this->teams['H2']->id}/members"), ['person_id' => $max, 'role' => 'player'])->assertCreated();
        $this->loginAs($this->admin)->postJson($this->url("teams/{$this->teams['MX']->id}/members"), ['person_id' => $max, 'role' => 'player'])->assertCreated();

        $this->assertArrayHasKey($max, $this->invited($training));
        $this->assertArrayNotHasKey($max, $this->invited($match));
        // Other kind of team: matches allowed.
        $this->assertArrayHasKey($max, $this->invited($mixedMatch));
        $row = collect($this->loginAs($coach)->getJson($this->url("teams/{$this->teams['H2']->id}/members"))->json('data'))->firstWhere('person.id', $max);
        $this->assertSame('Herren 1', $row['plays_higher_in']);

        // Leaving Herren 1 makes him a regular Herren 2 player again.
        $membership = $this->inTenant($this->tenant, fn () => TeamMembership::query()->where('team_id', $this->teams['H1']->id)->where('person_id', $max)->first());
        $this->loginAs($this->admin)->deleteJson($this->url("teams/{$this->teams['H1']->id}/members/{$membership->id}"))->assertOk();
        $this->assertArrayHasKey($max, $this->invited($match));

        // Moving Herren 2 above Herren 1 in the hierarchy turns it around.
        $this->loginAs($this->admin)->postJson($this->url("teams/{$this->teams['H1']->id}/members"), ['person_id' => $max, 'role' => 'player'])->assertCreated();
        $this->assertArrayNotHasKey($max, $this->invited($match));
        $leagues = collect($this->loginAs($this->admin)->getJson($this->url('leagues'))->json('data'))->keyBy('name');
        $this->loginAs($this->admin)->patchJson($this->url('teams/'.$this->teams['H2']->id), ['league_id' => $leagues['3. Liga']['id']])->assertOk();
        $this->assertArrayHasKey($max, $this->invited($match));
    }

    public function test_cross_nomination_only_upwards_and_by_gender(): void
    {
        // The trainer also coaches Herren 2 (to try the wrong direction below).
        $this->addToTeam($this->teams['H2'], $this->personOf($this->trainer), TeamRole::Trainer);
        $candidates = $this->loginAs($this->trainer)->getJson($this->url("teams/{$this->teams['H1']->id}/cross-nomination-candidates"))->assertOk()->json('data');
        $this->assertSame([['Paul', 'Herren 2']], array_map(fn ($c) => [$c['person']['first_name'], $c['home_team']['name']], $candidates));

        $this->nominate($this->lena, 'D1')->assertUnprocessable();
        $this->nominate($this->ben, 'U18')->assertUnprocessable();
        $this->nominate($this->paul, 'D1')->assertUnprocessable();
        $this->nominate($this->paul, 'H2')->assertCreated()->assertJsonPath('data.active', true);

        // Only the host team's trainer (or an admin).
        $this->loginAs($this->paul)->getJson($this->url("teams/{$this->teams['H1']->id}/cross-nomination-candidates"))->assertForbidden();
        // Not from the higher to the lower team.
        $this->nominate($this->max, 'H1', host: 'H2')->assertUnprocessable();
    }

    public function test_guests_are_invited_to_trainings_and_can_be_switched_off(): void
    {
        $nomination = $this->nominate($this->paul, 'H2')->json('data');
        $training = $this->training();
        $match = $this->training(['type' => 'match']);
        $paul = $this->personOf($this->paul)->id;

        $this->assertSame('cross_nomination', $this->invited($training['id'])[$paul]);
        $this->assertArrayNotHasKey($paul, $this->invited($match['id']));
        $this->assertCount(1, $this->loginAs($this->paul)->getJson($this->url('events'))->json('data'));

        $this->loginAs($this->trainer)->patchJson($this->url('cross-nominations/'.$nomination['id']), ['active' => false])->assertOk();
        $this->assertArrayNotHasKey($paul, $this->invited($training['id']));

        $this->loginAs($this->trainer)->patchJson($this->url('cross-nominations/'.$nomination['id']), ['active' => true])->assertOk();
        $this->assertArrayHasKey($paul, $this->invited($training['id']));
        $this->loginAs($this->trainer)->deleteJson($this->url('cross-nominations/'.$nomination['id']))->assertOk();
        $this->assertArrayNotHasKey($paul, $this->invited($training['id']));
    }

    public function test_guests_accept_if_allowed_with_the_same_deadline(): void
    {
        $this->nominate($this->paul, 'H2')->assertCreated();
        $training = $this->training(['max_participants' => 2, 'rsvp_deadline' => '2026-10-21T12:00']);

        $my = $this->loginAs($this->paul)->getJson($this->url('events'))->json('data.0.my.0');
        $this->assertSame([true, true], [$my['is_guest'], $my['can_accept']]);
        $this->respond($this->paul, $training['id'], 'yes')->assertOk();
        $this->respond($this->max, $training['id'], 'yes')->assertOk();
        // Full now (Max + Paul): the trainer cannot accept anymore.
        $this->respond($this->trainer, $training['id'], 'yes')->assertUnprocessable();

        // Same deadline as for the team's players.
        Carbon::setTestNow('2026-10-21 12:00:00');
        $this->respond($this->paul, $training['id'], 'no')->assertUnprocessable()->assertJsonPath('errors.status.0', 'Die Rückmeldefrist ist abgelaufen.');

        // Per training: guests may not accept, but may decline.
        Carbon::setTestNow('2026-10-19 10:00:00');
        $closed = $this->training(['guests_allowed' => false]);
        $this->respond($this->paul, $closed['id'], 'yes')->assertUnprocessable()->assertJsonPath('errors.status.0', 'Gäste können für dieses Training nicht zusagen.');
        $this->respond($this->paul, $closed['id'], 'no')->assertOk();
        $this->assertFalse($this->loginAs($this->paul)->getJson($this->url("events/{$closed['id']}"))->json('data.my.0.can_accept'));
    }

    public function test_training_groups_have_their_own_trainings(): void
    {
        $group = $this->loginAs($this->trainer)->postJson($this->url("teams/{$this->teams['H1']->id}/training-groups"), ['name' => 'Zuspielertraining'])
            ->assertCreated()->json('data');
        $this->loginAs($this->trainer)->patchJson($this->url('training-groups/'.$group['id']), ['person_ids' => [$this->personOf($this->max)->id]])->assertOk();
        $this->loginAs($this->trainer)->patchJson($this->url('training-groups/'.$group['id']), ['person_ids' => [$this->personOf($this->paul)->id]])->assertUnprocessable();

        $other = $this->member($this->tenant, 'olaf@example.org', 'Olaf', 'Other');
        $this->addToTeam($this->teams['H1'], $this->personOf($other));
        $this->nominate($this->paul, 'H2')->assertCreated();

        $training = $this->training(['training_group_id' => $group['id'], 'title' => 'Zuspiel']);
        $this->assertSame('Zuspielertraining', $training['training_group']['name']);
        $invited = $this->invited($training['id']);
        $this->assertEqualsCanonicalizing([$this->personOf($this->max)->id, $this->personOf($this->trainer)->id], array_keys($invited));

        // Group of another team is refused.
        $foreign = $this->loginAs($this->admin)->postJson($this->url("teams/{$this->teams['H2']->id}/training-groups"), ['name' => 'Annahme'])->json('data');
        $this->loginAs($this->trainer)->postJson($this->url('events'), [
            'type' => 'training', 'team_ids' => [$this->teams['H1']->id], 'training_group_id' => $foreign['id'],
            'starts_at' => '2026-10-22T19:30', 'ends_at' => '2026-10-22T21:30',
        ])->assertUnprocessable();

        // Changing the group updates the invitations; deleting it makes it a team training.
        $this->loginAs($this->trainer)->patchJson($this->url('training-groups/'.$group['id']), ['person_ids' => [$this->personOf($other)->id]])->assertOk();
        $this->assertArrayHasKey($this->personOf($other)->id, $this->invited($training['id']));
        $this->assertArrayNotHasKey($this->personOf($this->max)->id, $this->invited($training['id']));

        $this->loginAs($this->trainer)->deleteJson($this->url('training-groups/'.$group['id']))->assertOk();
        $this->assertCount(4, $this->invited($training['id']));
        $this->assertNull($this->loginAs($this->trainer)->getJson($this->url('events/'.$training['id']))->json('data.training_group'));
    }

    public function test_referee_license_is_shown_to_trainers(): void
    {
        $max = $this->personOf($this->max);
        $this->loginAs($this->max)->putJson($this->url("persons/{$max->id}/referee-license"), [
            'level' => 'D', 'license_number' => 'BY-1234', 'valid_until' => '2026-12-01',
        ])->assertOk()->assertJsonPath('data.referee_license.expires_soon', true);

        $roster = collect($this->loginAs($this->trainer)->getJson($this->url("teams/{$this->teams['H1']->id}/members"))->json('data'))
            ->firstWhere('person.id', $max->id);
        $this->assertSame(['level' => 'D', 'license_number' => 'BY-1234', 'valid_until' => '2026-12-01', 'expired' => false, 'expires_soon' => true], $roster['person']['referee_license']);

        $teammate = $this->member($this->tenant, 'olaf@example.org', 'Olaf', 'Other');
        $this->addToTeam($this->teams['H1'], $this->personOf($teammate));
        $roster = collect($this->loginAs($teammate)->getJson($this->url("teams/{$this->teams['H1']->id}/members"))->json('data'))->firstWhere('person.id', $max->id);
        $this->assertNull($roster['person']['referee_license']);
        $this->loginAs($teammate)->putJson($this->url("persons/{$max->id}/referee-license"), ['level' => 'A', 'valid_until' => '2030-01-01'])->assertForbidden();
        $this->loginAs($this->trainer)->deleteJson($this->url("persons/{$max->id}/referee-license"))->assertForbidden();
        $this->loginAs($this->admin)->deleteJson($this->url("persons/{$max->id}/referee-license"))->assertOk()->assertJsonPath('data.referee_license', null);
    }

    public function test_trainers_see_accepted_players_per_position(): void
    {
        $this->inTenant($this->tenant, fn () => $this->personOf($this->max)->forceFill(['primary_position' => 'setter'])->save());
        $this->nominate($this->paul, 'H2')->assertCreated();
        $training = $this->training();
        $this->respond($this->max, $training['id'], 'yes')->assertOk();
        $this->respond($this->paul, $training['id'], 'yes')->assertOk();
        $this->respond($this->trainer, $training['id'], 'yes')->assertOk();

        $detail = $this->loginAs($this->trainer)->getJson($this->url('events/'.$training['id']))->json('data');
        $this->assertEqualsCanonicalizing(['setter' => 1, 'unknown' => 1], $detail['positions']);
        $this->assertTrue(collect($detail['responses'])->firstWhere('person.name', 'Paul Zweiter')['person']['is_guest']);
        $this->assertNull($this->loginAs($this->max)->getJson($this->url('events/'.$training['id']))->json('data.positions'));
    }
}
