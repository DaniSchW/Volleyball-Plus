<?php

namespace Tests\Feature\Privacy;

use App\Enums\MemberStatus;
use App\Enums\PersonRelation;
use App\Enums\TeamRole;
use App\Models\CashBook;
use App\Models\CashEntry;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\EventResponse;
use App\Models\Person;
use App\Models\PersonAccount;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\Tenant;
use App\Models\TenantMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PersonDataTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Team $team;

    private TenantMember $admin;

    private TenantMember $trainer;

    private TenantMember $paul;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-19 10:00:00');

        $this->tenant = $this->tenant();
        $this->team = $this->team($this->tenant);
        $this->admin = $this->member($this->tenant, 'ada@example.org', 'Ada', 'Admin', admin: true, gender: 'female');
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

    private function withData(): string
    {
        $event = $this->loginAs($this->trainer)->postJson('/api/v1/t/ssv-ulm/events', [
            'type' => 'training', 'team_ids' => [$this->team->id], 'starts_at' => '2026-10-20T19:30', 'ends_at' => '2026-10-20T21:30',
        ])->assertCreated()->json('data');
        $person = $this->personOf($this->paul);
        $this->loginAs($this->paul)->postJson("/api/v1/t/ssv-ulm/events/{$event['id']}/responses", [
            'person_id' => $person->id, 'status' => 'no', 'reason' => 'Arbeit',
        ])->assertOk();
        $this->inTenant($this->tenant, function () use ($person) {
            $book = CashBook::create(['kind' => 'team', 'team_id' => $this->team->id, 'book_key' => $this->team->id]);
            CashEntry::create(['cash_book_id' => $book->id, 'booked_on' => '2026-10-01', 'amount_cents' => 500, 'description' => 'Strafe', 'person_id' => $person->id]);
            $chat = Chat::create(['kind' => 'team', 'team_id' => $this->team->id]);
            ChatMessage::create(['chat_id' => $chat->id, 'author_id' => $this->paul->id, 'body' => 'Bin heute nicht da']);
        });

        return $event['id'];
    }

    public function test_export_contains_the_persons_data(): void
    {
        $this->withData();
        $person = $this->personOf($this->paul);

        $response = $this->loginAs($this->paul)->get("/api/v1/t/ssv-ulm/persons/{$person->id}/export")->assertOk();
        $this->assertStringContainsString('attachment; filename="daten-paul-spieler.json"', $response->headers->get('Content-Disposition'));
        $data = $response->json();
        $this->assertSame('Paul', $data['profile']['first_name']);
        $this->assertSame('paul@example.org', $data['logins'][0]['email']);
        $this->assertSame([['team' => 'Herren 1', 'role' => 'player', 'joined_at' => null]], $data['teams']);
        $this->assertSame('Arbeit', $data['replies'][0]['reason']);
        $this->assertSame('Strafe', $data['cash_bookings'][0]['description']);
        $this->assertSame('Bin heute nicht da', $data['chat_messages'][0]['text']);

        // Not for trainers (only the person, parents, admins).
        $this->loginAs($this->trainer)->get("/api/v1/t/ssv-ulm/persons/{$person->id}/export")->assertForbidden();
        $this->loginAs($this->admin)->get("/api/v1/t/ssv-ulm/persons/{$person->id}/export")->assertOk();
        $this->loginAs($this->paul)->getJson("/api/v1/t/ssv-ulm/persons/{$person->id}")->assertJsonPath('data.can_manage_personal_data', true);
    }

    public function test_deleting_the_own_profile(): void
    {
        $eventId = $this->withData();
        $person = $this->personOf($this->paul);

        $this->loginAs($this->trainer)->deleteJson("/api/v1/t/ssv-ulm/persons/{$person->id}")->assertForbidden();
        $this->loginAs($this->paul)->deleteJson("/api/v1/t/ssv-ulm/persons/{$person->id}")->assertOk();

        $this->inTenant($this->tenant, function () use ($person) {
            $gone = Person::withTrashed()->find($person->id);
            $this->assertTrue($gone->trashed());
            $this->assertSame('Gelöschte Person', $gone->first_name.' '.$gone->last_name);
            $this->assertNull($gone->email);
            $this->assertSame(0, TeamMembership::query()->where('person_id', $person->id)->count());
            $this->assertSame(0, EventResponse::query()->where('person_id', $person->id)->count());
            $this->assertSame(0, PersonAccount::query()->where('person_id', $person->id)->count());
            // The booking stays for the cash book, without the person.
            $this->assertNull(CashEntry::query()->first()->person_id);
            $this->assertSame(MemberStatus::Disabled, TenantMember::find($this->paul->id)->status);
        });

        // The login no longer works in the department; the roster shows only the trainer.
        $this->loginAs($this->paul)->getJson('/api/v1/t/ssv-ulm/me')->assertForbidden();
        $this->assertCount(1, $this->loginAs($this->trainer)->getJson("/api/v1/t/ssv-ulm/teams/{$this->team->id}/members")->json('data'));
        $this->assertSame(1, $this->loginAs($this->trainer)->getJson("/api/v1/t/ssv-ulm/events/{$eventId}")->json('data.counts.open'));
    }

    public function test_parents_delete_their_child_and_the_last_admin_stays(): void
    {
        $parent = $this->member($this->tenant, 'eva@example.org', 'Eva', 'Eltern', gender: 'female');
        $child = $this->inTenant($this->tenant, function () use ($parent) {
            $child = Person::create(['first_name' => 'Kim', 'last_name' => 'Eltern', 'gender' => 'female', 'birthday' => '2014-05-01']);
            PersonAccount::create(['person_id' => $child->id, 'tenant_member_id' => $parent->id, 'relation' => PersonRelation::Guardian]);

            return $child;
        });
        $this->addToTeam($this->team, $child);

        $this->loginAs($this->trainer)->deleteJson("/api/v1/t/ssv-ulm/persons/{$child->id}")->assertForbidden();
        $this->loginAs($parent)->deleteJson("/api/v1/t/ssv-ulm/persons/{$child->id}")->assertOk();
        $this->assertSame(MemberStatus::Active, $this->inTenant($this->tenant, fn () => TenantMember::find($parent->id)->status));

        $this->loginAs($this->admin)->deleteJson('/api/v1/t/ssv-ulm/persons/'.$this->personOf($this->admin)->id)
            ->assertUnprocessable()->assertJsonPath('errors.person.0', 'Die Abteilung braucht mindestens einen Admin.');
    }
}
