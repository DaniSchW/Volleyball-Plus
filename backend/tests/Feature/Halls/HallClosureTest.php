<?php

namespace Tests\Feature\Halls;

use App\Enums\TeamRole;
use App\Jobs\SendPush;
use App\Models\Event;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\TenantMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class HallClosureTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Team $team;

    private TenantMember $admin;

    private TenantMember $trainer;

    private TenantMember $paul;

    private string $hallId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-19 10:00:00'); // a Monday
        Queue::fake();
        Mail::fake();

        $this->tenant = $this->tenant();
        $this->team = $this->team($this->tenant);
        $this->admin = $this->member($this->tenant, 'ada@example.org', 'Ada', 'Admin', admin: true, gender: 'female');
        $this->trainer = $this->member($this->tenant, 'tom@example.org', 'Tom', 'Trainer');
        $this->paul = $this->member($this->tenant, 'paul@example.org', 'Paul', 'Spieler');
        $this->addToTeam($this->team, $this->personOf($this->trainer), TeamRole::Trainer);
        $this->addToTeam($this->team, $this->personOf($this->paul));

        $this->hallId = collect($this->loginAs($this->admin)->postJson($this->url('halls'), [
            'name' => 'Kuhberghalle', 'courts' => 3, 'street' => 'Kuhbergstraße', 'house_number' => '12', 'postal_code' => '89077', 'city' => 'Ulm',
        ])->assertCreated()->json('data'))->firstWhere('name', 'Kuhberghalle')['id'];
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

    /** Tuesday trainings in the hall until 24 Nov: 20, 27 Oct, 3, 10, 17, 24 Nov. */
    private function series(): void
    {
        $this->loginAs($this->trainer)->postJson($this->url('events'), [
            'type' => 'training', 'team_ids' => [$this->team->id], 'title' => 'Training',
            'starts_at' => '2026-10-20T19:30', 'ends_at' => '2026-10-20T21:30',
            'location_name' => 'Kuhberghalle', 'address' => 'Kuhbergstraße 12, 89077 Ulm',
            'repeat' => ['weekdays' => [2], 'ends_on' => '2026-11-24'],
        ])->assertCreated();
    }

    /** @return array<string, string> date => status */
    private function trainings(): array
    {
        return $this->inTenant($this->tenant, fn () => Event::query()->where('type', 'training')->orderBy('starts_at')->get()
            ->mapWithKeys(fn (Event $e) => [$e->starts_at->toDateString() => $e->status->value])->all());
    }

    public function test_closing_a_hall_cancels_the_chosen_events_with_one_message(): void
    {
        $this->series();
        $match = $this->loginAs($this->trainer)->postJson($this->url('events'), [
            'type' => 'match', 'team_ids' => [$this->team->id], 'starts_at' => '2026-11-07T14:00', 'ends_at' => '2026-11-07T18:00',
            'location_name' => 'Kuhberghalle',
        ])->assertCreated()->json('data.id');
        $elsewhere = $this->postJson($this->url('events'), [
            'type' => 'extra', 'team_ids' => [$this->team->id], 'title' => 'Mannschaftsabend', 'starts_at' => '2026-11-04T19:00', 'ends_at' => '2026-11-04T22:00',
            'location_name' => 'Vereinsheim',
        ])->assertCreated();
        Queue::fake();

        $period = ['starts_on' => '2026-11-01', 'ends_on' => '2026-11-08'];
        $this->loginAs($this->trainer)->postJson($this->url("halls/{$this->hallId}/closures/preview"), $period)->assertForbidden();
        $preview = $this->loginAs($this->admin)->postJson($this->url("halls/{$this->hallId}/closures/preview"), $period)->assertOk()->json('data');
        $this->assertSame([['training', true], ['match', false]], collect($preview)->map(fn ($e) => [$e['type'], $e['suggested']])->all());

        $this->postJson($this->url("halls/{$this->hallId}/closures"), $period + [
            'reason' => 'Herbstferien', 'mode' => 'cancel', 'event_ids' => collect($preview)->where('suggested', true)->pluck('id')->all(),
        ])->assertCreated()->assertJsonPath('data.0.closures.0.reason', 'Herbstferien');

        $this->assertSame('cancelled', $this->trainings()['2026-11-03']);
        $this->assertSame('scheduled', $this->trainings()['2026-11-10']);
        $this->assertSame('scheduled', $this->inTenant($this->tenant, fn () => Event::query()->find($match)->status->value));
        // One message for the closure, not one per event.
        $pushes = Queue::pushed(SendPush::class, fn (SendPush $job) => $job->accountId === $this->paul->account_id)->map->payload->values();
        $this->assertSame(['Halle gesperrt: Kuhberghalle'], $pushes->pluck('title')->all());
        $this->assertStringContainsString('Herbstferien', $pushes[0]['body']);
    }

    public function test_deleting_and_series_skip_closed_days(): void
    {
        // Closed before the series exists: delete = the series leaves these days out.
        $this->loginAs($this->admin)->postJson($this->url("halls/{$this->hallId}/closures"), [
            'starts_on' => '2026-11-02', 'ends_on' => '2026-11-15', 'mode' => 'delete', 'event_ids' => [],
        ])->assertCreated();
        $this->series();
        $this->assertSame(['2026-10-20', '2026-10-27', '2026-11-17', '2026-11-24'], array_keys($this->trainings()));

        // Closing with "cancel": new dates are created as cancelled.
        $this->loginAs($this->admin)->postJson($this->url("halls/{$this->hallId}/closures"), [
            'starts_on' => '2026-12-01', 'ends_on' => '2026-12-01', 'mode' => 'cancel', 'event_ids' => [],
        ])->assertCreated();
        $this->loginAs($this->trainer)->postJson($this->url('events'), [
            'type' => 'training', 'team_ids' => [$this->team->id], 'title' => 'Wintertraining', 'starts_at' => '2026-12-01T19:30', 'ends_at' => '2026-12-01T21:30',
            'location_name' => 'Kuhberghalle', 'repeat' => ['weekdays' => [2], 'ends_on' => '2026-12-08'],
        ])->assertCreated();
        $this->assertSame('cancelled', $this->trainings()['2026-12-01']);
        $this->assertSame('scheduled', $this->trainings()['2026-12-08']);

        // Deleting existing events of a closure.
        $ids = collect($this->loginAs($this->admin)->postJson($this->url("halls/{$this->hallId}/closures/preview"), ['starts_on' => '2026-10-20', 'ends_on' => '2026-10-27'])->json('data'))->pluck('id')->all();
        $this->postJson($this->url("halls/{$this->hallId}/closures"), ['starts_on' => '2026-10-20', 'ends_on' => '2026-10-27', 'mode' => 'delete', 'event_ids' => $ids])->assertCreated();
        $this->assertArrayNotHasKey('2026-10-20', $this->trainings());

        // Lifting a closure: it no longer shows and no longer applies.
        $closure = collect($this->getJson($this->url('halls'))->json('data.0.closures'))->firstWhere('starts_on', '2026-11-02');
        $this->deleteJson($this->url("hall-closures/{$closure['id']}"))->assertOk();
        $this->assertNull(collect($this->getJson($this->url('halls'))->json('data.0.closures'))->firstWhere('starts_on', '2026-11-02'));
        // …so the daily job creates the skipped series dates again.
        $this->artisan('app:generate-series')->assertSuccessful();
        $this->assertSame('scheduled', $this->trainings()['2026-11-03'] ?? null);
    }

    public function test_period_is_checked(): void
    {
        $this->loginAs($this->admin)->postJson($this->url("halls/{$this->hallId}/closures"), [
            'starts_on' => '2026-11-10', 'ends_on' => '2026-11-01', 'mode' => 'cancel', 'event_ids' => [],
        ])->assertUnprocessable();
        $this->postJson($this->url("halls/{$this->hallId}/closures"), [
            'starts_on' => '2026-11-01', 'ends_on' => '2027-12-01', 'mode' => 'cancel', 'event_ids' => [],
        ])->assertUnprocessable();
        $this->postJson($this->url("halls/{$this->hallId}/closures"), [
            'starts_on' => '2026-11-01', 'ends_on' => '2026-11-02', 'mode' => 'archive', 'event_ids' => [],
        ])->assertUnprocessable();
    }
}
