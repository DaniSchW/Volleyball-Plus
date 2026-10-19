<?php

namespace Tests\Feature\Halls;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\TenantMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class HallTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Team $team;

    private Team $other;

    private TenantMember $admin;

    private TenantMember $trainer;

    private TenantMember $organizer;

    private TenantMember $paul;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->tenant();
        $this->team = $this->team($this->tenant);
        $this->other = $this->team($this->tenant, 'Damen 1', 'women');
        $this->admin = $this->member($this->tenant, 'ada@example.org', 'Ada', 'Admin', admin: true, gender: 'female');
        $this->trainer = $this->member($this->tenant, 'tom@example.org', 'Tom', 'Trainer');
        $this->organizer = $this->member($this->tenant, 'olga@example.org', 'Olga', 'Orga', gender: 'female');
        $this->paul = $this->member($this->tenant, 'paul@example.org', 'Paul', 'Spieler');
        $this->addToTeam($this->other, $this->personOf($this->trainer), TeamRole::Trainer);
        $this->addToTeam($this->team, $this->personOf($this->organizer), TeamRole::Organizer);
        $this->addToTeam($this->team, $this->personOf($this->paul));
    }

    private function hall(array $data = []): array
    {
        return collect($this->loginAs($this->admin)->postJson('/api/v1/t/ssv-ulm/halls', $data + ['name' => 'Kuhberghalle', 'courts' => 3])
            ->assertCreated()->json('data'))->firstWhere('name', $data['name'] ?? 'Kuhberghalle');
    }

    private function bookTeam(string $hallId, Team $team, string $start, string $end, array $data = []): TestResponse
    {
        return $this->loginAs($this->admin)->postJson("/api/v1/t/ssv-ulm/halls/{$hallId}/bookings", $data + [
            'team_id' => $team->id, 'weekday' => 2, 'start_time' => $start, 'end_time' => $end,
        ]);
    }

    public function test_conflicts_warn_but_do_not_block(): void
    {
        $hall = $this->hall();
        $this->loginAs($this->admin)->postJson("/api/v1/t/ssv-ulm/halls/{$hall['id']}/slots", ['weekday' => 2, 'start_time' => '18:00', 'end_time' => '22:00'])->assertCreated();

        $first = $this->bookTeam($hall['id'], $this->team, '19:30', '21:30')->assertCreated()->json('booking');
        $this->assertSame([], $first['conflicts']);
        $this->assertFalse($first['outside_slots']);

        // Same time, whole hall → conflict, saved anyway.
        $second = $this->bookTeam($hall['id'], $this->other, '20:00', '22:00')->assertCreated()->json('booking');
        $this->assertSame(['Herren 1'], array_column($second['conflicts'], 'team'));

        // Touching times and other courts do not conflict.
        $this->bookTeam($hall['id'], $this->other, '21:30', '22:00', ['courts' => [1]])->assertCreated();
        $this->loginAs($this->admin)->patchJson("/api/v1/t/ssv-ulm/hall-bookings/{$first['id']}", [
            'team_id' => $this->team->id, 'weekday' => 2, 'start_time' => '19:30', 'end_time' => '21:30', 'courts' => [1],
        ])->assertOk();
        $courtTwo = $this->bookTeam($hall['id'], $this->other, '19:00', '20:00', ['courts' => [2]])->json('booking');
        $this->assertSame([], $courtTwo['conflicts']);

        // Two of three parts overlap with one of them.
        $twoParts = $this->bookTeam($hall['id'], $this->other, '20:30', '21:00', ['courts' => [3, 2]])->json('booking');
        $this->assertSame([2, 3], $twoParts['courts']);
        $this->assertSame(['Damen 1'], array_column($twoParts['conflicts'], 'team'));
        // All parts = whole hall.
        $this->assertNull($this->bookTeam($hall['id'], $this->team, '18:00', '18:30', ['courts' => [1, 2, 3]])->json('booking.courts'));

        // Not within the hall's times.
        $late = $this->bookTeam($hall['id'], $this->other, '21:00', '23:00', ['courts' => [3]])->json('booking');
        $this->assertTrue($late['outside_slots']);

        // Different validity periods do not conflict.
        $hall2 = $this->hall(['name' => 'Sporthalle Nord', 'courts' => 1]);
        $this->bookTeam($hall2['id'], $this->team, '18:00', '20:00', ['valid_until' => '2026-12-31'])->assertCreated();
        $this->assertSame([], $this->bookTeam($hall2['id'], $this->other, '18:00', '20:00', ['valid_from' => '2027-01-01'])->json('booking.conflicts'));
        $this->assertCount(2, $this->bookTeam($hall2['id'], $this->other, '18:00', '20:00', ['valid_from' => '2026-12-01'])->json('booking.conflicts'));
    }

    public function test_validation(): void
    {
        $hall = $this->hall(['courts' => 2]);
        $this->bookTeam($hall['id'], $this->team, '20:00', '19:00')->assertUnprocessable();
        $this->bookTeam($hall['id'], $this->team, '19:00', '20:00', ['courts' => [3]])->assertUnprocessable();
        $this->bookTeam($hall['id'], $this->team, '19:00', '20:00', ['courts' => [2]])->assertCreated();

        // Fewer courts only when no booking uses the removed ones.
        $this->loginAs($this->admin)->patchJson("/api/v1/t/ssv-ulm/halls/{$hall['id']}", ['name' => 'Kuhberghalle', 'courts' => 1])->assertUnprocessable();

        $this->team->forceFill(['archived_at' => now()])->save();
        $this->bookTeam($hall['id'], $this->team, '19:00', '20:00')->assertUnprocessable();
    }

    public function test_trainers_see_the_plan_only_admins_edit(): void
    {
        $hall = $this->hall();
        $this->bookTeam($hall['id'], $this->team, '19:30', '21:30')->assertCreated();

        $this->loginAs($this->trainer)->getJson('/api/v1/t/ssv-ulm/halls')->assertOk()->assertJsonPath('data.0.bookings.0.team.name', 'Herren 1');
        $this->loginAs($this->organizer)->getJson('/api/v1/t/ssv-ulm/halls')->assertForbidden();
        $this->loginAs($this->paul)->getJson('/api/v1/t/ssv-ulm/halls')->assertForbidden();
        $this->loginAs($this->trainer)->postJson('/api/v1/t/ssv-ulm/halls', ['name' => 'X', 'courts' => 1])->assertForbidden();
        $this->loginAs($this->trainer)->postJson("/api/v1/t/ssv-ulm/halls/{$hall['id']}/bookings", [
            'team_id' => $this->other->id, 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '20:00',
        ])->assertForbidden();

        // The team's own times are visible to its members.
        $this->loginAs($this->paul)->getJson("/api/v1/t/ssv-ulm/teams/{$this->team->id}/hall-times")->assertOk()
            ->assertJsonPath('data.0.hall.name', 'Kuhberghalle')->assertJsonPath('data.0.start_time', '19:30');
        $this->loginAs($this->paul)->getJson("/api/v1/t/ssv-ulm/teams/{$this->other->id}/hall-times")->assertForbidden();

        // Deleting the hall removes its times and bookings.
        $this->loginAs($this->admin)->deleteJson("/api/v1/t/ssv-ulm/halls/{$hall['id']}")->assertOk()->assertJsonPath('data', []);
        $this->loginAs($this->paul)->getJson("/api/v1/t/ssv-ulm/teams/{$this->team->id}/hall-times")->assertJsonPath('data', []);
    }
}
