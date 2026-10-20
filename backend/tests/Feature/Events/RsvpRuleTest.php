<?php

namespace Tests\Feature\Events;

use App\Enums\TeamRole;
use App\Models\EventResponse;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\TenantMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RsvpRuleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Team $team;

    private TenantMember $trainer;

    private TenantMember $paul;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-19 10:00:00');
        $this->tenant = $this->tenant();
        $this->team = $this->team($this->tenant);
        $this->trainer = $this->member($this->tenant, 'tom@example.org', 'Tom', 'Trainer');
        $this->paul = $this->member($this->tenant, 'paul@example.org', 'Paul', 'Spieler');
        $this->addToTeam($this->team, $this->personOf($this->trainer), TeamRole::Trainer);
        $this->addToTeam($this->team, $this->personOf($this->paul));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function event(string $day, string $type = 'training', array $data = []): array
    {
        return $this->loginAs($this->trainer)->postJson('/api/v1/t/ssv-ulm/events', $data + [
            'type' => $type, 'team_ids' => [$this->team->id], 'starts_at' => "{$day}T19:30", 'ends_at' => "{$day}T21:30",
        ])->assertCreated()->json('data');
    }

    /** @return array<string, string> date => status */
    private function answers(): array
    {
        return $this->inTenant($this->tenant, fn () => EventResponse::query()->join('events', 'events.id', '=', 'event_responses.event_id')
            ->where('event_responses.person_id', $this->personOf($this->paul)->id)->orderBy('events.starts_at')
            ->get(['events.starts_at', 'event_responses.status'])
            ->mapWithKeys(fn ($r) => [Carbon::parse($r->starts_at)->toDateString() => $r->status->value])->all());
    }

    public function test_always_on_wednesdays_never_on_fridays(): void
    {
        // Trainings Wednesday and Friday for four weeks, a match on a Wednesday.
        $series = $this->event('2026-10-21', data: ['repeat' => ['weekdays' => [3, 5], 'ends_on' => '2026-11-13']]);
        $this->event('2026-10-29', 'match');
        // A Thursday training outside the series.
        $this->event('2026-10-22', data: ['title' => 'Extra']);
        $person = $this->personOf($this->paul)->id;

        $wednesday = $this->loginAs($this->paul)->postJson('/api/v1/t/ssv-ulm/rsvp-rules', [
            'person_id' => $person, 'status' => 'yes', 'team_id' => $this->team->id, 'event_type' => 'training', 'weekdays' => [3],
        ])->assertCreated()->assertJsonPath('applied', 4)->json('data');
        $this->loginAs($this->paul)->postJson('/api/v1/t/ssv-ulm/rsvp-rules', [
            'person_id' => $person, 'status' => 'no', 'reason' => 'Arbeit', 'series_id' => $series['series_id'],
            'weekdays' => [5], 'overwrite' => false,
        ])->assertCreated();

        $answers = $this->answers();
        $this->assertSame('yes', $answers['2026-10-21']);
        $this->assertSame('yes', $answers['2026-11-11']);
        // Only trainings on the chosen weekday, the series rule only for the series.
        $this->assertArrayNotHasKey('2026-10-29', $answers);
        $this->assertArrayNotHasKey('2026-10-22', $answers);
        // A rule for a series ignores weekdays: every event of the series without an answer – Wednesdays were answered already.
        $this->assertSame('no', $answers['2026-10-30']);
        $this->assertSame('no', $answers['2026-11-13']);
        $this->assertSame(4, count(array_filter($answers, fn ($s) => $s === 'yes')));

        // A single event can still be answered differently.
        $first = $series['id'];
        $this->loginAs($this->paul)->postJson("/api/v1/t/ssv-ulm/events/{$first}/responses", ['person_id' => $person, 'status' => 'no', 'reason' => 'Urlaub'])->assertOk();
        $this->assertSame('no', $this->answers()['2026-10-21']);

        // New matching events are answered automatically (extra training on a Wednesday) …
        $this->event('2026-11-18');
        $this->assertSame('yes', $this->answers()['2026-11-18']);
        // … but not by a deleted rule; the answers it gave stay.
        $this->loginAs($this->paul)->deleteJson("/api/v1/t/ssv-ulm/rsvp-rules/{$wednesday['id']}")->assertOk();
        $this->event('2026-11-25');
        $this->assertArrayNotHasKey('2026-11-25', $this->answers());
        $this->assertSame('yes', $this->answers()['2026-11-11']);

        $list = $this->loginAs($this->paul)->getJson('/api/v1/t/ssv-ulm/rsvp-rules')->assertOk()->json('data');
        $this->assertCount(1, $list['rules']);
        $this->assertSame($series['series_id'], $list['persons'][0]['teams'][0]['series'][0]['id']);
    }

    public function test_rules_respect_deadlines_limits_and_permissions(): void
    {
        $full = $this->event('2026-10-21', data: ['max_participants' => 1]);
        $other = $this->member($this->tenant, 'otto@example.org', 'Otto', 'Other');
        $this->addToTeam($this->team, $this->personOf($other));
        $this->loginAs($other)->postJson("/api/v1/t/ssv-ulm/events/{$full['id']}/responses", ['person_id' => $this->personOf($other)->id, 'status' => 'yes'])->assertOk();
        $this->event('2026-10-20', data: ['rsvp_deadline' => '2026-10-19T09:00']);
        $person = $this->personOf($this->paul)->id;

        $result = $this->loginAs($this->paul)->postJson('/api/v1/t/ssv-ulm/rsvp-rules', ['person_id' => $person, 'status' => 'yes'])->assertCreated()->json();
        $this->assertSame(0, $result['applied']);
        $this->assertCount(2, $result['skipped']);

        // Only for oneself and one's children.
        $this->loginAs($other)->postJson('/api/v1/t/ssv-ulm/rsvp-rules', ['person_id' => $person, 'status' => 'no'])->assertForbidden();
        $this->loginAs($other)->deleteJson("/api/v1/t/ssv-ulm/rsvp-rules/{$result['data']['id']}")->assertForbidden();
        $this->loginAs($this->paul)->postJson('/api/v1/t/ssv-ulm/rsvp-rules', ['person_id' => $person, 'status' => 'yes', 'team_id' => 'x'])->assertUnprocessable();
    }
}
