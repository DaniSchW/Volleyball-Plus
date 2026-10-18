<?php

namespace Tests\Feature\Events;

use App\Console\Commands\GenerateSeries;
use App\Enums\TeamRole;
use App\Models\Event;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\TenantMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SeriesNoticeCalendarTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Team $team;

    private TenantMember $trainer;

    private TenantMember $player;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-19 10:00:00'); // a Monday

        $this->tenant = $this->tenant();
        $this->team = $this->team($this->tenant);
        $this->trainer = $this->member($this->tenant, 'tom@example.org', 'Tom', 'Trainer');
        $this->player = $this->member($this->tenant, 'paul@example.org', 'Paul', 'Spieler');
        $this->addToTeam($this->team, $this->personOf($this->trainer), TeamRole::Trainer);
        $this->addToTeam($this->team, $this->personOf($this->player));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return list<Event>
     */
    private function seriesEvents(): array
    {
        return $this->inTenant($this->tenant, fn () => Event::query()->whereNotNull('series_id')->orderBy('starts_at')->get()->all());
    }

    private function createSeries(array $repeat = ['weekdays' => [2, 4], 'ends_on' => '2026-11-15']): array
    {
        return $this->loginAs($this->trainer)->postJson('/api/v1/t/ssv-ulm/events', [
            'type' => 'training', 'team_ids' => [$this->team->id], 'title' => 'Hallentraining',
            'starts_at' => '2026-10-20T19:30', 'ends_at' => '2026-10-20T21:30', 'rsvp_deadline' => '2026-10-20T17:30',
            'location_name' => 'Kuhberghalle', 'repeat' => $repeat,
        ])->assertCreated()->json('data');
    }

    public function test_weekly_series_keeps_local_time_across_daylight_saving(): void
    {
        $first = $this->createSeries();

        $events = $this->seriesEvents();
        // Tue/Thu from 20 Oct to 15 Nov 2026: 20, 22, 27, 29 Oct, 3, 5, 10, 12 Nov.
        $this->assertCount(8, $events);
        $this->assertSame('2026-10-20T17:30:00Z', $first['starts_at']);

        $local = array_map(fn (Event $e) => $e->starts_at->copy()->setTimezone('Europe/Berlin')->format('D d.m. H:i'), $events);
        $this->assertSame(['Tue 20.10. 19:30', 'Thu 22.10. 19:30', 'Tue 27.10. 19:30', 'Thu 29.10. 19:30', 'Tue 03.11. 19:30', 'Thu 05.11. 19:30', 'Tue 10.11. 19:30', 'Thu 12.11. 19:30'], $local);
        // Winter time from 25 Oct: UTC hour moves from 17:30 to 18:30.
        $this->assertSame('18:30', $events[2]->starts_at->format('H:i'));
        // Deadline two hours before every single date.
        $this->assertSame('16:30', $events[2]->rsvp_deadline->format('H:i'));
        // Everybody in the team is invited to each date.
        $this->assertSame(2, $this->inTenant($this->tenant, fn () => $events[7]->invitees()->count()));
    }

    public function test_every_second_week_and_rolling_window(): void
    {
        $this->createSeries(['weekdays' => [2], 'interval_weeks' => 2]);
        $events = $this->seriesEvents();

        $this->assertSame('2026-11-03', $events[1]->starts_at->toDateString());
        // Without end: about six months ahead …
        $this->assertLessThanOrEqual('2027-04-20', end($events)->starts_at->toDateString());
        $count = count($events);

        // … and the daily job keeps extending it.
        Carbon::setTestNow('2026-12-19 10:00:00');
        $this->artisan('app:generate-series')->assertSuccessful();
        $this->assertGreaterThan($count, count($this->seriesEvents()));
        $this->artisan('app:generate-series')->assertSuccessful();
        $this->assertFalse(GenerateSeries::isDue());
    }

    public function test_changing_the_series_updates_upcoming_dates_but_keeps_edited_ones(): void
    {
        $first = $this->createSeries();
        $events = $this->seriesEvents();
        $edited = $events[3];

        $this->patchJson("/api/v1/t/ssv-ulm/events/{$edited->id}", ['location_name' => 'Ausweichhalle'])->assertOk();

        $this->patchJson("/api/v1/t/ssv-ulm/events/{$first['id']}?series=1", [
            'type' => 'training', 'title' => 'Hallentraining', 'starts_at' => '2026-10-20T20:00', 'ends_at' => '2026-10-20T22:00',
            'location_name' => 'Kuhberghalle', 'repeat' => ['weekdays' => [2], 'ends_on' => '2026-11-15'],
        ])->assertOk();

        $after = collect($this->seriesEvents())->keyBy('id');
        // Tuesday dates moved to 20:00 local, Thursday dates are cancelled (not deleted).
        $this->assertSame('20:00', $after[$events[2]->id]->starts_at->copy()->setTimezone('Europe/Berlin')->format('H:i'));
        $this->assertSame('cancelled', $after[$events[1]->id]->status->value);
        // The individually edited date stays as it was.
        $this->assertSame('Ausweichhalle', $after[$edited->id]->location_name);
        $this->assertSame('scheduled', $after[$edited->id]->status->value);
    }

    public function test_ending_a_series_cancels_upcoming_dates(): void
    {
        $first = $this->createSeries();

        $this->postJson("/api/v1/t/ssv-ulm/events/{$first['id']}/end-series")->assertOk();

        $this->assertSame(['cancelled'], collect($this->seriesEvents())->pluck('status')->map->value->unique()->values()->all());
        $this->loginAs($this->player)->postJson("/api/v1/t/ssv-ulm/events/{$first['id']}/end-series")->assertForbidden();
    }

    public function test_series_only_for_trainings(): void
    {
        $this->loginAs($this->trainer)->postJson('/api/v1/t/ssv-ulm/events', [
            'type' => 'match', 'team_ids' => [$this->team->id], 'starts_at' => '2026-10-20T19:30', 'ends_at' => '2026-10-20T21:30',
            'repeat' => ['weekdays' => [2]],
        ])->assertStatus(422);
    }

    public function test_notices_for_a_period(): void
    {
        $other = $this->team($this->tenant, 'Damen 1', 'women');
        $lena = $this->member($this->tenant, 'lena@example.org', 'Lena', 'Damen', gender: 'female');
        $this->addToTeam($other, $this->personOf($lena));

        $this->loginAs($this->trainer)->postJson('/api/v1/t/ssv-ulm/notices', [
            'scope' => 'team', 'team_id' => $this->team->id, 'title' => 'Hallenschließung in den Ferien',
            'visible_from' => '2026-10-19T00:00', 'visible_until' => '2026-10-31T23:59',
        ])->assertCreated();
        $this->postJson('/api/v1/t/ssv-ulm/notices', ['scope' => 'tenant', 'title' => 'x', 'visible_from' => '2026-10-19T00:00', 'visible_until' => '2026-10-20T00:00'])->assertForbidden();
        $this->loginAs($this->player)->postJson('/api/v1/t/ssv-ulm/notices', [
            'scope' => 'team', 'team_id' => $this->team->id, 'title' => 'x', 'visible_from' => '2026-10-19T00:00', 'visible_until' => '2026-10-20T00:00',
        ])->assertForbidden();

        $this->assertSame('Hallenschließung in den Ferien', $this->getJson('/api/v1/t/ssv-ulm/notices')->json('data.0.title'));
        $this->assertCount(0, $this->loginAs($lena)->getJson('/api/v1/t/ssv-ulm/notices')->json('data'));

        Carbon::setTestNow('2026-11-01 10:00:00');
        $this->assertCount(0, $this->loginAs($this->player)->getJson('/api/v1/t/ssv-ulm/notices')->json('data'));
    }

    public function test_calendar_subscription(): void
    {
        $this->createSeries();
        $url = $this->loginAs($this->player)->getJson('/api/v1/t/ssv-ulm/calendar')->assertOk()->json('data.url');
        $this->assertStringStartsWith('https://localhost/api/v1/t/ssv-ulm/calendar/', $url);
        $path = substr($url, strlen('https://localhost'));

        // Calendar apps fetch without login.
        $ics = $this->withCredentials(false)->get($path)->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8')->getContent();
        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        $this->assertSame(8, substr_count($ics, 'BEGIN:VEVENT'));
        $this->assertStringContainsString("DTSTART:20261020T173000Z\r\n", $ics);
        $this->assertStringContainsString('SUMMARY:Hallentraining', $ics);
        $this->assertStringContainsString('LOCATION:Kuhberghalle', $ics);

        // Resetting invalidates the old address.
        $newUrl = $this->loginAs($this->player)->postJson('/api/v1/t/ssv-ulm/calendar/reset')->json('data.url');
        $this->assertNotSame($url, $newUrl);
        $this->get($path)->assertNotFound();
        $this->get('/api/v1/t/ssv-ulm/calendar/falsch.ics')->assertNotFound();
    }
}
