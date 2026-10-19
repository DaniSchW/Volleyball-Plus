<?php

namespace Tests\Feature\Events;

use App\Enums\TeamRole;
use App\Models\Event;
use App\Models\Person;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\TenantMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PositionLimitTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Team $team;

    private TenantMember $trainer;

    /** @var array<string, TenantMember> */
    private array $players = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-19 10:00:00');

        $this->tenant = $this->tenant();
        $this->team = $this->team($this->tenant);
        $this->trainer = $this->member($this->tenant, 'tom@example.org', 'Tom', 'Trainer');
        $this->addToTeam($this->team, $this->personOf($this->trainer), TeamRole::Trainer);
        foreach ([
            'sven' => ['setter', []],
            'sara' => ['setter', ['outside']],
            'sam' => ['setter', []],
            'otto' => ['outside', []],
            'lia' => [null, []],
        ] as $name => [$primary, $secondary]) {
            $this->players[$name] = $this->member($this->tenant, "{$name}@example.org", ucfirst($name), 'Test');
            $person = $this->personOf($this->players[$name]);
            $this->inTenant($this->tenant, fn () => $person->forceFill(['primary_position' => $primary, 'secondary_positions' => $secondary])->save());
            $this->addToTeam($this->team, $person);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function create(array $data = []): array
    {
        return $this->loginAs($this->trainer)->postJson('/api/v1/t/ssv-ulm/events', $data + [
            'type' => 'training', 'team_ids' => [$this->team->id],
            'starts_at' => '2026-10-20T19:30', 'ends_at' => '2026-10-20T21:30',
            'position_limits' => ['setter' => 2, 'outside' => 1, 'libero' => null],
        ])->assertCreated()->json('data');
    }

    private function yes(string $name, string $eventId, ?string $position = null, ?TenantMember $as = null): TestResponse
    {
        return $this->loginAs($as ?? $this->players[$name])->postJson("/api/v1/t/ssv-ulm/events/{$eventId}/responses", [
            'person_id' => $this->personOf($this->players[$name])->id, 'status' => 'yes', 'position' => $position,
        ]);
    }

    private function person(string $name): Person
    {
        return $this->personOf($this->players[$name]);
    }

    public function test_acceptance_stops_when_a_position_is_full(): void
    {
        $event = $this->create();
        $this->assertSame(['setter' => 2, 'outside' => 1], $event['position_limits']);

        $this->yes('sven', $event['id'])->assertOk();
        $this->yes('sam', $event['id'])->assertOk();
        // Setter is full: Sara counts as outside hitter (secondary position).
        $this->yes('sara', $event['id'])->assertOk();
        // Outside is full now too.
        $this->yes('otto', $event['id'])->assertUnprocessable()->assertJsonPath('errors.status.0', 'Für Außenangriff sind alle Plätze vergeben.');
        // Without position: only the overall maximum counts.
        $this->yes('lia', $event['id'])->assertOk();

        $data = $this->loginAs($this->trainer)->getJson("/api/v1/t/ssv-ulm/events/{$event['id']}")->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['setter' => 2, 'outside' => 1], $data['position_taken']);
        $this->assertEqualsCanonicalizing(['setter' => 2, 'outside' => 1, 'unknown' => 1], $data['positions']);
        $this->assertSame('outside', collect($data['responses'])->firstWhere('person.name', 'Sara Test')['as_position']);

        // Accepting again keeps the place.
        $this->yes('sara', $event['id'])->assertOk()->assertJsonPath('data.my.0.as_position', 'outside');
        $this->loginAs($this->players['sven'])->postJson("/api/v1/t/ssv-ulm/events/{$event['id']}/responses", [
            'person_id' => $this->person('sven')->id, 'status' => 'no', 'reason' => 'krank',
        ])->assertOk();
        // Sara switches to setter, which frees outside for Otto.
        $this->yes('sara', $event['id'], 'setter')->assertOk()->assertJsonPath('data.my.0.as_position', 'setter');
        $this->yes('otto', $event['id'])->assertOk();
    }

    public function test_chosen_position_must_be_in_the_profile_unless_a_manager_decides(): void
    {
        $event = $this->create();
        $this->yes('otto', $event['id'], 'libero')->assertUnprocessable()->assertJsonValidationErrors('position');
        $this->yes('otto', $event['id'], 'libero', $this->trainer)->assertOk();
        $this->assertSame('libero', $this->inTenant($this->tenant, fn () => Event::find($event['id'])->responses()->first()->as_position));
    }

    public function test_series_pass_the_limits_to_every_event(): void
    {
        $first = $this->create(['repeat' => ['weekdays' => [2], 'ends_on' => '2026-11-10']]);
        $limits = $this->inTenant($this->tenant, fn () => Event::query()->where('series_id', $first['series_id'])->pluck('position_limits')->all());
        $this->assertCount(4, $limits);
        $this->assertSame(['setter' => 2, 'outside' => 1], $limits[3]);
    }
}
