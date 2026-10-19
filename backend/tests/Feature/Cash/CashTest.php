<?php

namespace Tests\Feature\Cash;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\TenantMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CashTest extends TestCase
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
        Carbon::setTestNow('2026-10-19 10:00:00');

        $this->tenant = $this->tenant();
        $this->team = $this->team($this->tenant);
        $this->other = $this->team($this->tenant, 'Damen 1', 'women');
        $this->admin = $this->member($this->tenant, 'ada@example.org', 'Ada', 'Admin', admin: true, gender: 'female');
        $this->trainer = $this->member($this->tenant, 'tom@example.org', 'Tom', 'Trainer');
        $this->organizer = $this->member($this->tenant, 'olga@example.org', 'Olga', 'Orga', gender: 'female');
        $this->paul = $this->member($this->tenant, 'paul@example.org', 'Paul', 'Spieler');
        $this->addToTeam($this->team, $this->personOf($this->trainer), TeamRole::Trainer);
        $this->addToTeam($this->team, $this->personOf($this->organizer), TeamRole::Organizer);
        $this->addToTeam($this->team, $this->personOf($this->paul));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function books(TenantMember $as): TestResponse
    {
        return $this->loginAs($as)->getJson('/api/v1/t/ssv-ulm/cash');
    }

    private function bookId(TenantMember $as, string $kind, ?string $teamId = null): string
    {
        return collect($this->books($as)->assertOk()->json('data.books'))
            ->first(fn ($b) => $b['kind'] === $kind && $b['team_id'] === $teamId)['id'];
    }

    private function book(TenantMember $as, string $bookId, array $entry): TestResponse
    {
        return $this->loginAs($as)->postJson("/api/v1/t/ssv-ulm/cash-books/{$bookId}/entries", $entry + [
            'booked_on' => '2026-10-01', 'description' => 'Buchung',
        ]);
    }

    public function test_team_book_with_balance_and_running_balance(): void
    {
        $book = $this->bookId($this->trainer, 'team', $this->team->id);
        $this->loginAs($this->trainer)->patchJson("/api/v1/t/ssv-ulm/cash-books/{$book}", ['opening_balance_cents' => 5000])->assertOk();

        $this->book($this->trainer, $book, ['amount_cents' => 2000, 'category' => 'Strafe', 'description' => 'Zu spät', 'person_id' => $this->personOf($this->paul)->id, 'booked_on' => '2026-09-10'])->assertCreated();
        $this->book($this->organizer, $book, ['amount_cents' => -1550, 'category' => 'Getränke', 'description' => 'Wasser', 'booked_on' => '2026-10-05'])->assertCreated();
        $this->book($this->trainer, $book, ['amount_cents' => 0])->assertUnprocessable();

        $data = $this->loginAs($this->trainer)->getJson("/api/v1/t/ssv-ulm/cash-books/{$book}")->assertOk()->json('data');
        $this->assertSame(5450, $data['summary']['balance']);
        $this->assertSame(2000, $data['summary']['income']);
        $this->assertSame(-1550, $data['summary']['expenses']);
        // Newest first, each with the balance after it.
        $this->assertSame(['Wasser', 'Zu spät'], array_column($data['entries'], 'description'));
        $this->assertSame([5450, 7000], array_column($data['entries'], 'balance_after'));
        $this->assertSame('Paul Spieler', $data['entries'][1]['person']['name']);
        $this->assertSame('Olga Orga', $data['entries'][0]['created_by']);
        $this->assertContains('Mitgliedsbeitrag', $data['categories']);
        $this->assertCount(3, $data['persons']);

        // Period: carried balance at the start, only bookings within.
        $period = $this->loginAs($this->trainer)->getJson("/api/v1/t/ssv-ulm/cash-books/{$book}?from=2026-10-01&to=2026-10-31")->json('data');
        $this->assertSame(7000, $period['summary']['carried']);
        $this->assertSame(0, $period['summary']['income']);
        $this->assertSame(5450, $period['summary']['closing']);
        $this->assertCount(1, $period['entries']);
    }

    public function test_cancelled_entries_stay_visible_but_do_not_count(): void
    {
        $book = $this->bookId($this->trainer, 'team', $this->team->id);
        $id = $this->book($this->trainer, $book, ['amount_cents' => 1000])->json('data.id');

        $this->loginAs($this->paul)->postJson("/api/v1/t/ssv-ulm/cash-entries/{$id}/cancel")->assertForbidden();
        $this->loginAs($this->trainer)->postJson("/api/v1/t/ssv-ulm/cash-entries/{$id}/cancel")->assertOk();
        $this->loginAs($this->trainer)->postJson("/api/v1/t/ssv-ulm/cash-entries/{$id}/cancel")->assertUnprocessable();

        $data = $this->loginAs($this->trainer)->getJson("/api/v1/t/ssv-ulm/cash-books/{$book}")->json('data');
        $this->assertSame(0, $data['summary']['balance']);
        $this->assertNotNull($data['entries'][0]['cancelled_at']);
        $this->assertSame('Tom Trainer', $data['entries'][0]['cancelled_by']);
        $this->assertNull($data['entries'][0]['balance_after']);
    }

    public function test_who_sees_which_book(): void
    {
        // Players have no cash book, trainers/organizers only their team's.
        $this->assertSame([], $this->books($this->paul)->assertOk()->json('data.books'));
        $this->assertSame(['Herren 1'], array_column($this->books($this->organizer)->json('data.books'), 'name'));
        $this->assertNull($this->books($this->trainer)->json('data.total'));

        $department = $this->bookId($this->admin, 'department');
        $otherBook = $this->bookId($this->admin, 'team', $this->other->id);
        $this->loginAs($this->trainer)->getJson("/api/v1/t/ssv-ulm/cash-books/{$department}")->assertForbidden();
        $this->loginAs($this->trainer)->getJson("/api/v1/t/ssv-ulm/cash-books/{$otherBook}")->assertForbidden();
        $this->book($this->trainer, $department, ['amount_cents' => 100])->assertForbidden();
        $this->book($this->paul, $this->bookId($this->trainer, 'team', $this->team->id), ['amount_cents' => 100])->assertForbidden();

        // Admin overview: department + all teams, with totals.
        $this->book($this->admin, $department, ['amount_cents' => -30000, 'description' => 'Bälle'])->assertCreated();
        $this->book($this->admin, $otherBook, ['amount_cents' => 4000])->assertCreated();
        $this->book($this->trainer, $this->bookId($this->trainer, 'team', $this->team->id), ['amount_cents' => 1000])->assertCreated();
        $overview = $this->books($this->admin)->json('data');
        $this->assertSame(['Abteilungskasse', 'Damen 1', 'Herren 1'], array_column($overview['books'], 'name'));
        $this->assertSame(5000, $overview['teams_total']);
        $this->assertSame(-25000, $overview['total']);

        // Archived teams with bookings stay in the overview.
        $this->other->forceFill(['archived_at' => now()])->save();
        $this->assertTrue(collect($this->books($this->admin)->json('data.books'))->firstWhere('name', 'Damen 1')['archived']);
    }

    public function test_csv_export(): void
    {
        $book = $this->bookId($this->trainer, 'team', $this->team->id);
        $this->book($this->trainer, $book, ['amount_cents' => 1250, 'description' => 'Beitrag; Oktober', 'category' => 'Mitgliedsbeitrag']);
        $this->book($this->trainer, $book, ['amount_cents' => -500, 'description' => 'Tape', 'booked_on' => '2026-10-02']);

        $response = $this->loginAs($this->trainer)->get("/api/v1/t/ssv-ulm/cash-books/{$book}/export");
        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFDatum;Beschreibung;", $csv);
        $this->assertStringContainsString('2026-10-01;"Beitrag; Oktober";Mitgliedsbeitrag;;12,50;;12,50;;"Tom Trainer"', $csv);
        $this->assertStringContainsString('2026-10-02;Tape;;;;5,00;7,50;;"Tom Trainer"', $csv);

        $this->loginAs($this->paul)->get("/api/v1/t/ssv-ulm/cash-books/{$book}/export")->assertForbidden();
    }
}
