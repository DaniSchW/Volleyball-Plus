<?php

namespace Tests\Feature\Activity;

use App\Enums\TeamRole;
use App\Models\ActivityLog;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\TenantMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Team $team;

    private TenantMember $admin;

    private TenantMember $trainer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-19 10:00:00');
        $this->tenant = $this->tenant();
        $this->team = $this->team($this->tenant);
        $this->admin = $this->member($this->tenant, 'ada@example.org', 'Ada', 'Admin', admin: true, gender: 'female');
        $this->trainer = $this->member($this->tenant, 'tom@example.org', 'Tom', 'Trainer');
        $this->addToTeam($this->team, $this->personOf($this->trainer), TeamRole::Trainer);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_admins_see_who_changed_what(): void
    {
        $event = $this->loginAs($this->trainer)->postJson('/api/v1/t/ssv-ulm/events', [
            'type' => 'training', 'team_ids' => [$this->team->id], 'title' => 'Hallentraining',
            'starts_at' => '2026-10-20T19:30', 'ends_at' => '2026-10-20T21:30',
        ])->assertCreated()->json('data');
        $this->loginAs($this->trainer)->patchJson("/api/v1/t/ssv-ulm/events/{$event['id']}", ['title' => 'Techniktraining'])->assertOk();
        // A series logs once, not every generated date.
        $this->loginAs($this->trainer)->postJson('/api/v1/t/ssv-ulm/events', [
            'type' => 'training', 'team_ids' => [$this->team->id], 'title' => 'Dienstagstraining',
            'starts_at' => '2026-10-20T19:30', 'ends_at' => '2026-10-20T21:30', 'repeat' => ['weekdays' => [2]],
        ])->assertCreated();

        $this->loginAs($this->trainer)->getJson('/api/v1/t/ssv-ulm/activity')->assertForbidden();
        $log = collect($this->loginAs($this->admin)->getJson('/api/v1/t/ssv-ulm/activity')->assertOk()->json('data'));

        $this->assertSame(['updated', 'Techniktraining (20.10.2026 19:30)', 'Tom Trainer'],
            [$log->firstWhere('label', 'Techniktraining (20.10.2026 19:30)')['action'], 'Techniktraining (20.10.2026 19:30)', $log->firstWhere('label', 'Techniktraining (20.10.2026 19:30)')['actor']]);
        $this->assertSame(1, $log->where('type', 'event_series')->where('action', 'created')->count());
        $this->assertSame(0, $log->where('label', 'like', 'Dienstagstraining (%')->where('type', 'event')->count());
        $this->assertSame(1, $log->where('type', 'event')->where('action', 'created')->count());
    }

    public function test_entries_are_kept_for_seven_days(): void
    {
        $this->loginAs($this->admin)->postJson('/api/v1/t/ssv-ulm/halls', ['name' => 'Kuhberghalle', 'courts' => 3])->assertCreated();
        $this->assertCount(1, collect($this->loginAs($this->admin)->getJson('/api/v1/t/ssv-ulm/activity')->json('data'))->where('type', 'hall'));

        Carbon::setTestNow('2026-10-26 11:00:00');
        $this->assertCount(0, collect($this->loginAs($this->admin)->getJson('/api/v1/t/ssv-ulm/activity')->json('data'))->where('type', 'hall'));
        $this->artisan('app:prune-activity')->assertSuccessful();
        $this->assertSame(0, ActivityLog::withoutGlobalScopes()->where('subject_type', 'hall')->count());
    }
}
