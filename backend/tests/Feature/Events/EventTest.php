<?php

namespace Tests\Feature\Events;

use App\Enums\PersonRelation;
use App\Enums\TeamRole;
use App\Models\Event;
use App\Models\EventInvitee;
use App\Models\EventResponseLog;
use App\Models\MinorPermission;
use App\Models\Person;
use App\Models\PersonAccount;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\TenantMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class EventTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Team $team;

    private TenantMember $admin;

    private TenantMember $trainer;

    private TenantMember $organizer;

    private TenantMember $player;

    private TenantMember $teammate;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-19 10:00:00');

        $this->tenant = $this->tenant();
        $this->team = $this->team($this->tenant);
        $this->admin = $this->member($this->tenant, 'ada@example.org', 'Ada', 'Admin', admin: true, gender: 'female');
        $this->trainer = $this->member($this->tenant, 'tom@example.org', 'Tom', 'Trainer');
        $this->organizer = $this->member($this->tenant, 'olga@example.org', 'Olga', 'Orga', gender: 'female');
        $this->player = $this->member($this->tenant, 'paul@example.org', 'Paul', 'Spieler');
        $this->teammate = $this->member($this->tenant, 'mia@example.org', 'Mia', 'Mit', gender: 'female');
        $this->addToTeam($this->team, $this->personOf($this->trainer), TeamRole::Trainer);
        $this->addToTeam($this->team, $this->personOf($this->organizer), TeamRole::Organizer);
        $this->addToTeam($this->team, $this->personOf($this->player));
        $this->addToTeam($this->team, $this->personOf($this->teammate));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function create(TenantMember $as, array $data = []): array
    {
        return $this->loginAs($as)->postJson('/api/v1/t/ssv-ulm/events', $data + [
            'type' => 'training',
            'team_ids' => [$this->team->id],
            'starts_at' => '2026-10-20T19:30',
            'ends_at' => '2026-10-20T21:30',
            'location_name' => 'Kuhberghalle',
            'address' => 'Egginger Weg 12, 89077 Ulm',
        ])->assertCreated()->json('data');
    }

    private function respond(TenantMember $as, string $eventId, Person $person, string $status, ?string $reason = null): TestResponse
    {
        return $this->loginAs($as)->postJson("/api/v1/t/ssv-ulm/events/{$eventId}/responses", [
            'person_id' => $person->id, 'status' => $status, 'reason' => $reason,
        ]);
    }

    public function test_trainer_creates_a_training_and_the_whole_team_is_invited(): void
    {
        $event = $this->create($this->trainer);

        $this->assertSame('Training', $event['title']);
        // Entered as local time (Europe/Berlin, summer time) → stored in UTC.
        $this->assertSame('2026-10-20T17:30:00Z', $event['starts_at']);
        $this->assertSame(['yes' => 0, 'no' => 0, 'maybe' => 0, 'open' => 4], $event['counts']);
        $this->assertSame('https://www.google.com/maps/dir/?api=1&destination=Egginger%20Weg%2012%2C%2089077%20Ulm', $event['directions_url']);

        $feed = $this->loginAs($this->player)->getJson('/api/v1/t/ssv-ulm/events')->assertOk()->json('data');
        $this->assertCount(1, $feed);
        $this->assertSame([['person_id' => $this->personOf($this->player)->id, 'name' => 'Paul', 'is_self' => true, 'status' => null, 'reason' => null,
            'as_position' => null, 'positions' => [], 'is_guest' => false, 'can_accept' => true, 'guests_open_at' => null, 'can_respond' => true]], $feed[0]['my']);
    }

    public function test_who_may_create_events(): void
    {
        $this->create($this->organizer);
        $this->loginAs($this->player)->postJson('/api/v1/t/ssv-ulm/events', [
            'type' => 'training', 'team_ids' => [$this->team->id], 'starts_at' => '2026-10-20T19:30', 'ends_at' => '2026-10-20T21:30',
        ])->assertForbidden();

        // Department-wide events: admins only.
        $this->loginAs($this->trainer)->postJson('/api/v1/t/ssv-ulm/events', [
            'type' => 'extra', 'scope' => 'tenant', 'title' => 'Hauptversammlung', 'starts_at' => '2026-11-10T19:00', 'ends_at' => '2026-11-10T21:00',
        ])->assertForbidden();
    }

    public function test_admin_sets_an_info_event_for_all_teams(): void
    {
        $other = $this->team($this->tenant, 'Damen 1', 'women');
        $lena = $this->member($this->tenant, 'lena@example.org', 'Lena', 'Damen', gender: 'female');
        $this->addToTeam($other, $this->personOf($lena));

        $event = $this->create($this->admin, [
            'type' => 'extra', 'scope' => 'tenant', 'response_mode' => 'info', 'title' => 'Hauptversammlung Hauptverein',
            'team_ids' => [], 'starts_at' => '2026-11-10T19:00', 'ends_at' => '2026-11-10T21:00',
        ]);

        $this->assertSame('info', $event['response_mode']);
        $this->assertCount(2, $event['teams']);
        $mine = $this->loginAs($lena)->getJson('/api/v1/t/ssv-ulm/events')->json('data.0');
        $this->assertSame('Hauptversammlung Hauptverein', $mine['title']);
        $this->assertFalse($mine['my'][0]['can_respond']);

        $this->respond($lena, $event['id'], $this->personOf($lena), 'yes')->assertStatus(422);
    }

    public function test_roster_changes_update_upcoming_invitations(): void
    {
        $event = $this->create($this->trainer);
        $newcomer = $this->member($this->tenant, 'neu@example.org', 'Nico', 'Neu');

        $membership = $this->addToTeam($this->team, $this->personOf($newcomer));
        $this->assertCount(1, $this->loginAs($newcomer)->getJson('/api/v1/t/ssv-ulm/events')->json('data'));

        $this->inTenant($this->tenant, fn () => $membership->delete());
        $this->assertCount(0, $this->loginAs($newcomer)->getJson('/api/v1/t/ssv-ulm/events')->json('data'));
        $this->assertSame(4, $this->inTenant($this->tenant, fn () => EventInvitee::where('event_id', $event['id'])->count()));
    }

    public function test_reply_rules_reason_deadline_and_maximum(): void
    {
        $this->loginAs($this->trainer)->patchJson("/api/v1/t/ssv-ulm/teams/{$this->team->id}", ['decline_reason_required' => true])->assertOk();
        $event = $this->create($this->trainer, ['max_participants' => 1, 'rsvp_deadline' => '2026-10-20T12:00']);
        $paul = $this->personOf($this->player);
        $mia = $this->personOf($this->teammate);

        $this->respond($this->player, $event['id'], $paul, 'no')->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->respond($this->player, $event['id'], $paul, 'no', 'Arbeit')->assertOk();
        $this->respond($this->player, $event['id'], $paul, 'yes')->assertOk()->assertJsonPath('data.counts.yes', 1)->assertJsonPath('data.is_full', true);

        // Full: no further acceptances, declines still possible.
        $this->respond($this->teammate, $event['id'], $mia, 'yes')->assertStatus(422)->assertJsonValidationErrors('status');
        $this->respond($this->teammate, $event['id'], $mia, 'maybe')->assertOk();

        // Nobody may answer for somebody else, except the event's managers.
        $this->respond($this->teammate, $event['id'], $paul, 'no', 'x')->assertForbidden();

        Carbon::setTestNow('2026-10-20 12:30:00');
        $this->respond($this->player, $event['id'], $paul, 'no', 'krank')->assertStatus(422);
        $this->respond($this->trainer, $event['id'], $paul, 'no', 'krank (Info per Telefon)')->assertOk();

        $log = $this->inTenant($this->tenant, fn () => EventResponseLog::where('person_id', $paul->id)->orderBy('created_at')->get());
        $this->assertSame(['no', 'yes', 'no'], $log->pluck('new_status')->all());
        $this->assertTrue($log->last()->after_deadline);
    }

    public function test_children_answer_only_with_their_parents_permission(): void
    {
        $parent = $this->member($this->tenant, 'eltern@example.org', 'Eva', 'Eltern', gender: 'female');
        $kid = $this->member($this->tenant, 'kim@example.org', 'Kim', 'Kind', gender: 'female');
        $kidPerson = $this->personOf($kid);
        $this->inTenant($this->tenant, function () use ($parent, $kidPerson) {
            PersonAccount::create(['person_id' => $kidPerson->id, 'tenant_member_id' => $parent->id, 'relation' => PersonRelation::Guardian]);
            MinorPermission::create(['person_id' => $kidPerson->id, 'own_login' => true, 'can_rsvp' => false]);
        });
        $this->addToTeam($this->team, $kidPerson);
        $event = $this->create($this->trainer);

        $this->respond($kid, $event['id'], $kidPerson, 'yes')->assertForbidden();
        $mine = $this->loginAs($parent)->getJson('/api/v1/t/ssv-ulm/events')->json('data.0.my');
        $this->assertSame('Kim', $mine[0]['name']);
        $this->assertTrue($mine[0]['can_respond']);
        $this->respond($parent, $event['id'], $kidPerson, 'yes')->assertOk();

        $this->inTenant($this->tenant, fn () => MinorPermission::query()->update(['can_rsvp' => true]));
        $this->respond($kid, $event['id'], $kidPerson, 'no')->assertOk();
    }

    public function test_teammates_see_names_or_counts_as_the_trainer_decides(): void
    {
        $event = $this->create($this->trainer);
        $this->respond($this->player, $event['id'], $this->personOf($this->player), 'no', 'Urlaub')->assertOk();

        $rows = $this->loginAs($this->teammate)->getJson("/api/v1/t/ssv-ulm/events/{$event['id']}")->json('data.responses');
        $paul = collect($rows)->firstWhere('person.name', 'Paul Spieler');
        $this->assertSame('no', $paul['status']);
        $this->assertNull($paul['reason'], 'reasons are hidden from teammates by default');

        $trainerView = $this->loginAs($this->trainer)->getJson("/api/v1/t/ssv-ulm/events/{$event['id']}")->json('data.responses');
        $this->assertSame('Urlaub', collect($trainerView)->firstWhere('person.name', 'Paul Spieler')['reason']);

        $this->patchJson("/api/v1/t/ssv-ulm/teams/{$this->team->id}", ['rsvp_visibility' => 'counts'])->assertOk();
        $this->loginAs($this->teammate)->getJson("/api/v1/t/ssv-ulm/events/{$event['id']}")
            ->assertJsonPath('data.responses', null)
            ->assertJsonPath('data.counts.no', 1);
        // Organizers organise – they keep seeing names.
        $this->assertNotNull($this->loginAs($this->organizer)->getJson("/api/v1/t/ssv-ulm/events/{$event['id']}")->json('data.responses'));
    }

    public function test_minimum_participants_flag(): void
    {
        $this->loginAs($this->trainer)->patchJson("/api/v1/t/ssv-ulm/teams/{$this->team->id}", ['min_participants' => 2])->assertOk();
        $event = $this->create($this->trainer);
        $this->assertTrue($event['below_minimum']);

        $this->respond($this->player, $event['id'], $this->personOf($this->player), 'yes');
        $this->respond($this->teammate, $event['id'], $this->personOf($this->teammate), 'yes')->assertJsonPath('data.below_minimum', false);
    }

    public function test_training_does_not_take_place_when_the_minimum_cannot_be_reached(): void
    {
        $event = $this->create($this->trainer, ['min_participants' => 2]);
        $this->assertSame('open', $event['minimum_state']);
        $invited = $event['counts']['open'];

        // Everybody but one declines: at most one can come.
        $persons = collect([$this->trainer, $this->teammate, $this->organizer])->map(fn ($m) => $this->personOf($m));
        $response = null;
        foreach ($persons->take($invited - 1) as $person) {
            $response = $this->loginAs($this->trainer)->postJson("/api/v1/t/ssv-ulm/events/{$event['id']}/responses", ['person_id' => $person->id, 'status' => 'no']);
        }
        $response->assertJsonPath('data.minimum_state', 'unreachable')->assertJsonPath('data.minimum_possible', 1);

        // One changes their mind – reachable again, and reached with a second yes.
        $this->loginAs($this->trainer)->postJson("/api/v1/t/ssv-ulm/events/{$event['id']}/responses", ['person_id' => $persons[0]->id, 'status' => 'maybe'])
            ->assertJsonPath('data.minimum_state', 'open');
        $this->respond($this->player, $event['id'], $this->personOf($this->player), 'yes');
        $this->loginAs($this->trainer)->postJson("/api/v1/t/ssv-ulm/events/{$event['id']}/responses", ['person_id' => $persons[0]->id, 'status' => 'yes'])
            ->assertJsonPath('data.minimum_state', 'reached');
    }

    public function test_training_with_its_own_minimum(): void
    {
        $this->loginAs($this->trainer)->patchJson("/api/v1/t/ssv-ulm/teams/{$this->team->id}", ['min_participants' => 2])->assertOk();
        $event = $this->create($this->trainer, ['min_participants' => 1]);
        $this->assertSame([1, 1], [$event['min_participants'], $event['own_min_participants']]);
        $this->respond($this->player, $event['id'], $this->personOf($this->player), 'yes')->assertJsonPath('data.below_minimum', false);
    }

    public function test_feed_pages_with_a_cursor(): void
    {
        foreach (range(1, 25) as $day) {
            $date = Carbon::parse('2026-10-20')->addDays($day)->toDateString();
            $this->create($this->trainer, ['starts_at' => "{$date}T19:30", 'ends_at' => "{$date}T21:30"]);
        }

        $first = $this->loginAs($this->player)->getJson('/api/v1/t/ssv-ulm/events')->assertOk();
        $this->assertCount(20, $first->json('data'));
        $this->assertNotNull($first->json('next_cursor'));

        $second = $this->getJson('/api/v1/t/ssv-ulm/events?cursor='.urlencode($first->json('next_cursor')))->assertOk();
        $this->assertCount(5, $second->json('data'));
        $this->assertNull($second->json('next_cursor'));
        $this->assertLessThan($second->json('data.0.starts_at'), $first->json('data.19.starts_at'));

        // Garbage cursors are ignored, not an error.
        $this->getJson('/api/v1/t/ssv-ulm/events?cursor=kaputt')->assertOk();

        Carbon::setTestNow('2026-12-01 10:00:00');
        $this->assertCount(0, $this->getJson('/api/v1/t/ssv-ulm/events')->json('data'));
        $this->assertCount(20, $this->getJson('/api/v1/t/ssv-ulm/events?past=1')->json('data'));
    }

    public function test_cancelling_and_editing(): void
    {
        $event = $this->create($this->trainer);

        $this->loginAs($this->trainer)->patchJson("/api/v1/t/ssv-ulm/events/{$event['id']}", ['title' => 'Techniktraining', 'starts_at' => '2026-10-20T19:00', 'ends_at' => '2026-10-20T21:00'])
            ->assertOk()->assertJsonPath('data.title', 'Techniktraining')->assertJsonPath('data.starts_at', '2026-10-20T17:00:00Z');
        $this->postJson("/api/v1/t/ssv-ulm/events/{$event['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->respond($this->player, $event['id'], $this->personOf($this->player), 'yes')->assertStatus(422);

        $this->loginAs($this->player)->patchJson("/api/v1/t/ssv-ulm/events/{$event['id']}", ['title' => 'x'])->assertForbidden();
    }

    public function test_events_of_other_departments_are_invisible(): void
    {
        $event = $this->create($this->trainer);
        $other = $this->tenant('tsv-anders', 'TSV Anders');
        $stranger = $this->member($other, 'fremd@example.org', admin: true);

        $this->loginAs($stranger)->getJson("/api/v1/t/tsv-anders/events/{$event['id']}")->assertNotFound();
        $this->assertCount(0, $this->getJson('/api/v1/t/tsv-anders/events')->json('data'));
        $this->postJson("/api/v1/t/tsv-anders/events/{$event['id']}/responses", ['person_id' => $this->personOf($this->player)->id, 'status' => 'yes'])->assertNotFound();
    }

    public function test_match_with_opponent_and_logos(): void
    {
        Storage::fake('local');
        $opponent = $this->loginAs($this->trainer)->postJson('/api/v1/t/ssv-ulm/opponents', ['name' => 'TSV Bahn Walze'])->assertCreated()->json('data');
        $this->post("/api/v1/t/ssv-ulm/opponents/{$opponent['id']}/logo", ['image' => UploadedFile::fake()->image('logo.png', 300, 300)])->assertOk();
        $this->post("/api/v1/t/ssv-ulm/teams/{$this->team->id}/logo", ['image' => UploadedFile::fake()->image('team.png', 300, 300)])->assertOk();

        $event = $this->create($this->trainer, ['type' => 'match', 'opponent_id' => $opponent['id'], 'is_home' => true]);

        $this->assertSame('Spiel gegen TSV Bahn Walze', $event['title']);
        $this->assertStringContainsString('/files/', $event['opponent_logo_url']);
        $this->assertStringContainsString('/files/', $event['own_logo_url']);
        $this->loginAs($this->player)->get($event['opponent_logo_url'])->assertOk();

        // Department logo: admins only, publicly visible (login page).
        $this->loginAs($this->trainer)->post('/api/v1/t/ssv-ulm/logo', ['image' => UploadedFile::fake()->image('l.png', 50, 50)])->assertForbidden();
        $this->loginAs($this->admin)->post('/api/v1/t/ssv-ulm/logo', ['image' => UploadedFile::fake()->image('l.png', 50, 50)])->assertOk();
        $this->withCredentials(false)->get('/api/v1/t/ssv-ulm/logo')->assertOk();
        $this->getJson('/api/v1/t/ssv-ulm')->assertJsonPath('data.logo_url', '/api/v1/t/ssv-ulm/logo');
    }
}
