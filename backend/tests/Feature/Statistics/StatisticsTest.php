<?php

namespace Tests\Feature\Statistics;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\TenantMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StatisticsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Team $team;

    private TenantMember $trainer;

    private TenantMember $organizer;

    private TenantMember $paul;

    private TenantMember $mia;

    /** @var array<string, string> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-01 08:00:00');

        $this->tenant = $this->tenant();
        $this->team = $this->team($this->tenant);
        $this->trainer = $this->member($this->tenant, 'tom@example.org', 'Tom', 'Trainer');
        $this->organizer = $this->member($this->tenant, 'olga@example.org', 'Olga', 'Orga', gender: 'female');
        $this->paul = $this->member($this->tenant, 'paul@example.org', 'Paul', 'Spieler');
        $this->mia = $this->member($this->tenant, 'mia@example.org', 'Mia', 'Mit', gender: 'female');
        $this->addToTeam($this->team, $this->personOf($this->trainer), TeamRole::Trainer);
        $this->addToTeam($this->team, $this->personOf($this->organizer), TeamRole::Organizer);
        $this->addToTeam($this->team, $this->personOf($this->paul));
        $this->addToTeam($this->team, $this->personOf($this->mia));

        foreach (['t1' => ['training', '2026-10-05'], 't2' => ['training', '2026-10-12'], 'm1' => ['match', '2026-10-17'], 'x' => ['training', '2026-10-19'], 'future' => ['training', '2026-11-02']] as $key => [$type, $day]) {
            $this->events[$key] = $this->loginAs($this->trainer)->postJson('/api/v1/t/ssv-ulm/events', [
                'type' => $type, 'team_ids' => [$this->team->id], 'starts_at' => "{$day}T19:30", 'ends_at' => "{$day}T21:30",
                'rsvp_deadline' => "{$day}T12:00",
            ])->assertCreated()->json('data.id');
        }
        $this->reply($this->paul, $this->paul, 't1', 'yes');
        $this->reply($this->paul, $this->paul, 't2', 'yes');
        $this->reply($this->paul, $this->paul, 'm1', 'yes');
        $this->reply($this->mia, $this->mia, 't1', 'yes');
        $this->reply($this->mia, $this->mia, 't2', 'no');
        // Cancelled events do not count.
        $this->loginAs($this->trainer)->postJson("/api/v1/t/ssv-ulm/events/{$this->events['x']}/cancel")->assertOk();

        Carbon::setTestNow('2026-10-17 20:00:00');
        // Entered by the trainer after the deadline.
        $this->reply($this->trainer, $this->mia, 'm1', 'maybe');
        Carbon::setTestNow('2026-10-25 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function reply(TenantMember $as, TenantMember $for, string $event, string $status): void
    {
        $this->loginAs($as)->postJson("/api/v1/t/ssv-ulm/events/{$this->events[$event]}/responses", [
            'person_id' => $this->personOf($for)->id, 'status' => $status, 'reason' => 'x',
        ])->assertOk();
    }

    private function stats(TenantMember $as, string $query = ''): array
    {
        return $this->loginAs($as)->getJson("/api/v1/t/ssv-ulm/teams/{$this->team->id}/statistics{$query}")->assertOk()->json('data');
    }

    public function test_trainers_see_everybody_with_rates_for_the_season(): void
    {
        $data = $this->stats($this->trainer);

        $this->assertSame(['from' => '2026-07-01', 'to' => '2027-06-30'], $data['period']);
        $this->assertSame(['from' => '2026-09-27', 'to' => '2026-10-25'], $data['presets']['last_4_weeks']);
        $this->assertTrue($data['can_see_all']);
        $this->assertSame(3, $data['event_count']);
        $this->assertSame(['Paul Spieler', 'Mia Mit'], array_column(array_column($data['rows'], 'person'), 'name'));
        $this->assertSame(
            ['invited' => 3, 'yes' => 3, 'maybe' => 0, 'no' => 0, 'open' => 0, 'late' => 0, 'rate' => 100],
            array_intersect_key($data['rows'][0], array_flip(['invited', 'yes', 'maybe', 'no', 'open', 'late', 'rate'])),
        );
        $this->assertSame(
            ['invited' => 3, 'yes' => 1, 'maybe' => 1, 'no' => 1, 'open' => 0, 'late' => 1, 'rate' => 33],
            array_intersect_key($data['rows'][1], array_flip(['invited', 'yes', 'maybe', 'no', 'open', 'late', 'rate'])),
        );
    }

    public function test_players_see_only_their_own_rate_and_organizers_none(): void
    {
        $data = $this->stats($this->mia);
        $this->assertFalse($data['can_see_all']);
        $this->assertSame(['Mia Mit'], array_column(array_column($data['rows'], 'person'), 'name'));

        $this->assertSame([], $this->stats($this->organizer)['rows']);

        $outsider = $this->member($this->tenant, 'x@example.org');
        $this->loginAs($outsider)->getJson("/api/v1/t/ssv-ulm/teams/{$this->team->id}/statistics")->assertForbidden();
    }

    public function test_period_and_event_type_can_be_chosen(): void
    {
        $data = $this->stats($this->trainer, '?from=2026-10-10&to=2026-10-20&types[]=training');
        $this->assertSame(1, $data['event_count']);
        $this->assertSame(['Paul Spieler' => 100, 'Mia Mit' => 0], array_combine(array_column(array_column($data['rows'], 'person'), 'name'), array_column($data['rows'], 'rate')));

        $this->loginAs($this->trainer)->getJson("/api/v1/t/ssv-ulm/teams/{$this->team->id}/statistics?from=2026-10-20&to=2026-10-01")->assertUnprocessable();
        $this->loginAs($this->trainer)->getJson("/api/v1/t/ssv-ulm/teams/{$this->team->id}/statistics?types[]=party")->assertUnprocessable();
    }

    public function test_history_shows_who_answered_when(): void
    {
        $mia = $this->personOf($this->mia)->id;
        $url = "/api/v1/t/ssv-ulm/teams/{$this->team->id}/statistics/persons/{$mia}";

        $history = $this->loginAs($this->trainer)->getJson($url)->assertOk()->json('data');
        $this->assertSame(['maybe', 'no', 'yes'], array_column($history, 'status'));
        $this->assertSame([true, false, false], array_column($history, 'after_deadline'));
        $this->assertSame('2026-10-17T20:00:00Z', $history[0]['responded_at']);

        $this->loginAs($this->mia)->getJson($url)->assertOk();
        $this->loginAs($this->paul)->getJson($url)->assertForbidden();
        $this->loginAs($this->organizer)->getJson($url)->assertForbidden();
    }
}
