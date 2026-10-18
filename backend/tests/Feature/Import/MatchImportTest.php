<?php

namespace Tests\Feature\Import;

use App\Enums\TeamRole;
use App\Jobs\SendPush;
use App\Mail\NotificationMail;
use App\Models\Event;
use App\Models\Opponent;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\TenantMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * With the real SAMS exports (.ics/.ical and CSV) of SSV Ulm 1846, Landesliga 3.
 */
class MatchImportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Team $team;

    private TenantMember $trainer;

    private TenantMember $player;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-18 19:00:00');
        Queue::fake();
        Mail::fake();

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

    private function file(string $name, ?string $content = null, string $as = ''): UploadedFile
    {
        $content ??= file_get_contents(base_path('tests/Fixtures/import/'.$name));

        return UploadedFile::fake()->createWithContent($as ?: $name, $content);
    }

    private function preview(UploadedFile $file, array $extra = [], ?TenantMember $as = null): TestResponse
    {
        return $this->loginAs($as ?? $this->trainer)->post("/api/v1/t/ssv-ulm/teams/{$this->team->id}/match-import/preview", ['file' => $file] + $extra, ['Accept' => 'application/json']);
    }

    private function import(UploadedFile $file, array $keys, string $own = 'SSV Ulm 1846'): TestResponse
    {
        return $this->loginAs($this->trainer)->post("/api/v1/t/ssv-ulm/teams/{$this->team->id}/match-import", ['file' => $file, 'own_name' => $own, 'keys' => $keys], ['Accept' => 'application/json']);
    }

    private function selectedKeys(array $preview): array
    {
        return array_column(array_filter($preview['rows'], fn ($r) => $r['selected']), 'key');
    }

    private function events(): array
    {
        return $this->inTenant($this->tenant, fn () => Event::query()->with('opponent')->orderBy('starts_at')->get()->all());
    }

    public function test_ics_preview_finds_own_team_home_and_away(): void
    {
        $data = $this->preview($this->file('sams.ics'))->assertOk()->json('data');

        $this->assertSame(['name' => 'SSV Ulm 1846', 'count' => 16], $data['names'][0]);
        $this->assertSame('SSV Ulm 1846', $data['own_name']);
        $this->assertCount(16, $data['rows']);
        // Matches of last week are shown but not preselected.
        $this->assertSame([true, true, false], array_column(array_slice($data['rows'], 0, 3), 'past'));
        $this->assertCount(14, $this->selectedKeys($data));

        $away = $data['rows'][2];
        $this->assertSame(['away', 'SG VfB FN/TSG Ailingen', 'Spiel gegen SG VfB FN/TSG Ailingen', 'new'], [$away['role'], $away['opponent'], $away['title'], $away['status']]);
        $this->assertSame(['2026-10-25T13:00:00Z', '2026-10-25T15:00:00Z'], [$away['starts_at'], $away['ends_at']]);
        $this->assertSame(['Sporthalle Ailingen', 'Sporthalle Ailingen, Fohlenstraße 19, 88048 Friedrichshafen'], [$away['location_name'], $away['address']]);
        // Folded lines are joined.
        $this->assertSame('Sporthalle Eugen-Bolz-Schule, Steinacherstr. 39, 88339 Bad Waldsee', $data['rows'][3]['address']);
        $this->assertSame('home', $data['rows'][0]['role']);
    }

    public function test_import_creates_matches_with_opponents_and_one_message(): void
    {
        $keys = $this->selectedKeys($this->preview($this->file('sams.ics'))->json('data'));
        $this->import($this->file('sams.ics'), $keys)->assertOk()->assertJsonPath('data', ['created' => 14, 'updated' => 0]);

        $events = $this->events();
        $this->assertCount(14, $events);
        $this->assertSame(['match', false, 'SG VfB FN/TSG Ailingen'], [$events[0]->type->value, $events[0]->is_home, $events[0]->opponent->name]);
        $this->assertSame(8, $this->inTenant($this->tenant, fn () => Opponent::count()));
        $this->assertSame('SSV Ulm 1846', $this->team->refresh()->import_name);

        // The player is invited and gets one push for the whole schedule.
        $pushes = Queue::pushed(SendPush::class, fn (SendPush $job) => $job->accountId === $this->player->account_id);
        $this->assertCount(1, $pushes);
        $this->assertSame('Herren 1: 14 neue Termine im Spielplan', $pushes->first()->payload['title']);
        $this->assertCount(14, $this->loginAs($this->player)->getJson('/api/v1/t/ssv-ulm/events')->json('data'));

        // Importing the same file again changes nothing.
        $again = $this->preview($this->file('sams.ics'))->json('data');
        $this->assertSame([], $this->selectedKeys($again));
        $this->assertSame('same', $again['rows'][2]['status']);
    }

    public function test_reimport_updates_moved_matches_and_lists_removed_ones(): void
    {
        $ics = file_get_contents(base_path('tests/Fixtures/import/sams.ics'));
        $this->import($this->file('sams.ics'), $this->selectedKeys($this->preview($this->file('sams.ics'))->json('data')))->assertOk();

        // Match at Bad Waldsee moved by an hour, last match dropped from the file.
        $changed = str_replace('DTSTART;TZID=Europe/Berlin:20261107T133000', 'DTSTART;TZID=Europe/Berlin:20261107T143000', $ics);
        $changed = preg_replace('/BEGIN:VEVENT(?:(?!END:VEVENT).)*c06b661e(?:(?!END:VEVENT).)*END:VEVENT\r?\n/s', '', $changed);

        $data = $this->preview($this->file('x.ics', $changed))->json('data');
        $moved = collect($data['rows'])->firstWhere('opponent', 'TG Bad Waldsee 2');
        $this->assertSame(['update', ['starts_at', 'ends_at'], true], [$moved['status'], $moved['changes'], $moved['selected']]);
        $this->assertSame(['Spiel gegen SG Bad Wurzach/Leutkirch'], array_column($data['missing'], 'title'));

        $this->import($this->file('x.ics', $changed), $this->selectedKeys($data))->assertOk()->assertJsonPath('data', ['created' => 0, 'updated' => 1]);
        $event = collect($this->events())->first(fn ($e) => $e->opponent?->name === 'TG Bad Waldsee 2');
        $this->assertSame('2026-11-07 13:30:00', $event->starts_at->toDateTimeString());
        // The change is announced like any other (mail by default).
        Mail::assertQueued(NotificationMail::class, fn ($m) => $m->title === 'Termin geändert: Spiel gegen TG Bad Waldsee 2');
    }

    public function test_sams_csv_with_windows_encoding_referees_and_results(): void
    {
        $data = $this->preview($this->file('sams.csv'))->assertOk()->json('data');

        $this->assertSame('SSV Ulm 1846', $data['own_name']);
        $this->assertCount(16, $data['rows']);
        $first = $data['rows'][0];
        $this->assertSame("Spieltag 1 · Landesliga 3 Männer\nSchiedsgericht: VSG Illertal\nErgebnis: 3:2", $first['details']);
        $this->assertSame(['Blauringhalle', 'Blauringhalle, 89077 Ulm'], [$first['location_name'], $first['address']]);
        $this->assertContains('SC Göggingen', array_column($data['rows'], 'opponent'));
        $this->assertSame('2026-12-05T17:00:00Z', collect($data['rows'])->firstWhere('opponent', 'SC Göggingen')['starts_at']);

        // A file where our team only referees: "Schiedsgericht" date.
        $csv = "\"Datum und Uhrzeit\";\"#\";\"Mannschaft 1\";\"Mannschaft 2\";\"Schiedsgericht\";\"Gastgeber\";\"Austragungsort\"\n"
            ."\"08.11.2026, 14:00:00\";\"20\";\"TG Biberach\";\"SC Göggingen\";\"SSV Ulm 1846\";\"TG Biberach\";\"Wilhelm-Leger Halle (88400 Biberach)\"\n";
        $row = $this->preview($this->file('r.csv', $csv), ['own_name' => 'SSV Ulm 1846'])->json('data.rows.0');
        $this->assertSame(['referee', 'Schiedsgericht: TG Biberach – SC Göggingen', null], [$row['role'], $row['title'], $row['opponent']]);
        $this->import($this->file('r.csv', $csv), [$row['key']])->assertOk();
        $this->assertSame('extra', $this->events()[0]->type->value);
    }

    public function test_csv_after_ics_recognises_the_same_matches(): void
    {
        $this->import($this->file('sams.ics'), $this->selectedKeys($this->preview($this->file('sams.ics'))->json('data')))->assertOk();

        $data = $this->preview($this->file('sams.csv'))->json('data');
        $this->assertSame([], $this->selectedKeys($data));
        $this->assertSame([], $data['missing']);
    }

    public function test_rules(): void
    {
        $this->preview($this->file('sams.ics'), as: $this->player)->assertForbidden();
        $this->preview($this->file('x.csv', "Name;Wert\nA;1\n"))->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->preview($this->file('x.pdf', 'BEGIN:VCALENDAR'))->assertUnprocessable();
        $this->import($this->file('sams.ics'), ['uid:x'], own: 'Gibt es nicht')->assertUnprocessable()->assertJsonValidationErrors('own_name');
        $this->preview($this->file('sams.ical'))->assertOk()->assertJsonCount(16, 'data.rows');
    }
}
