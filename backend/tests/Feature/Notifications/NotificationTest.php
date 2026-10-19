<?php

namespace Tests\Feature\Notifications;

use App\Console\Commands\CheckSsl;
use App\Enums\PersonRelation;
use App\Enums\TeamRole;
use App\Jobs\SendPush;
use App\Mail\NotificationMail;
use App\Models\Account;
use App\Models\Event;
use App\Models\EventResponse;
use App\Models\MinorPermission;
use App\Models\Notice;
use App\Models\NotificationPreference;
use App\Models\PersonAccount;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\TenantMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Team $team;

    private TenantMember $trainer;

    private TenantMember $player;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-19 10:00:00');
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

    private function createEvent(array $data = []): array
    {
        return $this->loginAs($this->trainer)->postJson('/api/v1/t/ssv-ulm/events', $data + [
            'type' => 'training', 'team_ids' => [$this->team->id], 'title' => 'Hallentraining',
            'starts_at' => '2026-10-22T19:30', 'ends_at' => '2026-10-22T21:30', 'location_name' => 'Kuhberghalle',
        ])->assertCreated()->json('data');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pushesTo(TenantMember $member): array
    {
        return Queue::pushed(SendPush::class, fn (SendPush $job) => $job->accountId === $member->account_id)
            ->map(fn (SendPush $job) => $job->payload)->values()->all();
    }

    public function test_new_event_is_pushed_to_invitees_but_not_to_its_creator(): void
    {
        $event = $this->createEvent();

        $pushes = $this->pushesTo($this->player);
        $this->assertCount(1, $pushes);
        $this->assertSame('Neuer Termin: Hallentraining', $pushes[0]['title']);
        $this->assertSame('Do 22.10. 19:30 Uhr · Kuhberghalle', $pushes[0]['body']);
        $this->assertSame(config('app.url').'/ssv-ulm/events/'.$event['id'], $pushes[0]['url']);
        $this->assertSame([], $this->pushesTo($this->trainer));
        Mail::assertNothingQueued();
    }

    public function test_a_new_series_is_announced_once(): void
    {
        $this->createEvent(['repeat' => ['weekdays' => [2, 4], 'ends_on' => '2026-12-31']]);

        $this->assertSame(['Neue Trainingsreihe: Hallentraining'], array_column($this->pushesTo($this->player), 'title'));
    }

    public function test_changes_and_cancellations_also_go_out_by_mail(): void
    {
        $event = $this->createEvent();
        $url = '/api/v1/t/ssv-ulm/events/'.$event['id'];

        // Text changes are not worth a message.
        $this->loginAs($this->trainer)->patchJson($url, ['description' => 'Bitte Bälle mitbringen'])->assertOk();
        $this->assertCount(1, $this->pushesTo($this->player));

        $this->loginAs($this->trainer)->patchJson($url, ['starts_at' => '2026-10-22T20:00', 'ends_at' => '2026-10-22T22:00'])->assertOk();
        $this->assertSame('Termin geändert: Hallentraining', $this->pushesTo($this->player)[1]['title']);
        Mail::assertQueued(NotificationMail::class, fn (NotificationMail $m) => $m->hasTo('paul@example.org') && $m->body === 'Jetzt Do 22.10. 20:00 Uhr · Kuhberghalle');

        $this->loginAs($this->trainer)->postJson($url.'/cancel')->assertOk();
        $this->loginAs($this->trainer)->postJson($url.'/cancel')->assertOk();
        $this->assertSame(['Neuer Termin: Hallentraining', 'Termin geändert: Hallentraining', 'Abgesagt: Hallentraining'], array_column($this->pushesTo($this->player), 'title'));
        Mail::assertQueued(NotificationMail::class, fn (NotificationMail $m) => $m->title === 'Abgesagt: Hallentraining');

        $this->loginAs($this->trainer)->postJson($url.'/cancel?restore=1')->assertOk();
        $this->assertSame('Findet doch statt: Hallentraining', $this->pushesTo($this->player)[3]['title']);
    }

    public function test_members_choose_channels_per_category(): void
    {
        $url = '/api/v1/t/ssv-ulm/notification-preferences';
        $this->loginAs($this->player)->getJson($url)->assertOk()
            ->assertJsonPath('data.0', ['category' => 'event_new', 'push' => true, 'email' => false])
            ->assertJsonPath('data.1', ['category' => 'event_changed', 'push' => true, 'email' => true]);

        $this->loginAs($this->player)->putJson($url, ['preferences' => [
            ['category' => 'event_new', 'push' => false, 'email' => true],
            ['category' => 'event_changed', 'push' => true, 'email' => true],
        ]])->assertOk()->assertJsonPath('data.0.email', true);
        $this->loginAs($this->player)->putJson($url, ['preferences' => [['category' => 'spam', 'push' => true, 'email' => true]]])->assertUnprocessable();

        $this->createEvent();

        $this->assertSame([], $this->pushesTo($this->player));
        Mail::assertQueued(NotificationMail::class, fn (NotificationMail $m) => $m->hasTo('paul@example.org') && $m->title === 'Neuer Termin: Hallentraining');
        // Only deviations from the defaults are stored.
        $this->assertSame(1, $this->inTenant($this->tenant, fn () => NotificationPreference::count()));
    }

    public function test_children_reach_their_parents_and_only_with_permission_themselves(): void
    {
        $parent = $this->member($this->tenant, 'eltern@example.org', 'Eva', 'Eltern', gender: 'female');
        Account::query()->where('email', 'eltern@example.org')->update(['locale' => 'en']);
        $kid = $this->member($this->tenant, 'kim@example.org', 'Kim', 'Kind', gender: 'female');
        $kidPerson = $this->personOf($kid);
        $this->inTenant($this->tenant, function () use ($parent, $kidPerson) {
            PersonAccount::create(['person_id' => $kidPerson->id, 'tenant_member_id' => $parent->id, 'relation' => PersonRelation::Guardian]);
            MinorPermission::create(['person_id' => $kidPerson->id, 'own_login' => false]);
        });
        $this->addToTeam($this->team, $kidPerson);

        $this->createEvent();
        $this->assertSame([], $this->pushesTo($kid));
        $this->assertSame('New event: Hallentraining', $this->pushesTo($parent)[0]['title']);
        $this->assertSame('Thu, Oct 22, 7:30 PM · Kuhberghalle', $this->pushesTo($parent)[0]['body']);

        $this->inTenant($this->tenant, fn () => MinorPermission::query()->update(['own_login' => true]));
        $this->createEvent(['title' => 'Zusatztraining']);
        $this->assertCount(1, $this->pushesTo($kid));
        $this->assertCount(2, $this->pushesTo($parent));
    }

    public function test_reminder_24_hours_before_the_deadline_to_those_without_reply(): void
    {
        $teammate = $this->member($this->tenant, 'mia@example.org', 'Mia', 'Mit', gender: 'female');
        $this->addToTeam($this->team, $this->personOf($teammate));
        $event = $this->createEvent(['rsvp_deadline' => '2026-10-22T12:00']);
        $this->loginAs($teammate)->postJson("/api/v1/t/ssv-ulm/events/{$event['id']}/responses", [
            'person_id' => $this->personOf($teammate)->id, 'status' => 'yes',
        ])->assertOk();

        $this->artisan('app:send-notifications')->assertSuccessful();
        $this->assertCount(1, $this->pushesTo($this->player));

        // Deadline 22.10. 12:00 local = 10:00 UTC → reminder from 21.10. 10:00 UTC.
        Carbon::setTestNow('2026-10-21 10:30:00');
        $this->artisan('app:send-notifications')->assertSuccessful();
        $this->artisan('app:send-notifications')->assertSuccessful();

        $reminders = array_values(array_filter($this->pushesTo($this->player), fn ($p) => str_starts_with($p['title'], 'Bitte antworten')));
        $this->assertCount(1, $reminders);
        $this->assertSame('Do 22.10. 19:30 Uhr – Antwort bitte bis Do 22.10. 12:00 Uhr.', $reminders[0]['body']);
        $this->assertCount(1, $this->pushesTo($teammate)); // only the "new event" message
        // Player and trainer (who is invited too) have not answered.
        $this->assertSame(2, Queue::pushed(SendPush::class, fn (SendPush $j) => str_starts_with($j->payload['title'], 'Bitte antworten'))->count());
    }

    public function test_reminder_for_taken_tasks_the_day_before(): void
    {
        $event = $this->createEvent(['type' => 'helper', 'title' => 'Vereinsfest']);
        $task = $this->loginAs($this->trainer)->postJson("/api/v1/t/ssv-ulm/events/{$event['id']}/tasks", ['title' => 'Kuchen', 'slots' => 3])->assertCreated()->json('data.tasks.0.id');
        $this->loginAs($this->player)->postJson("/api/v1/t/ssv-ulm/tasks/{$task}/assignments", ['person_id' => $this->personOf($this->player)->id])->assertOk();

        $this->artisan('app:send-notifications')->assertSuccessful();
        $taskPushes = fn () => array_values(array_filter($this->pushesTo($this->player), fn ($p) => str_starts_with($p['title'], 'Deine Aufgabe')));
        $this->assertSame([], $taskPushes());

        // Start 22.10. 19:30 local = 17:30 UTC → reminder from 21.10. 17:30 UTC, once.
        Carbon::setTestNow('2026-10-21 18:00:00');
        $this->artisan('app:send-notifications')->assertSuccessful();
        $this->artisan('app:send-notifications')->assertSuccessful();
        $this->assertCount(1, $taskPushes());
        $this->assertSame('Deine Aufgabe: Kuchen', $taskPushes()[0]['title']);
        $this->assertSame('Vereinsfest · Do 22.10. 19:30 Uhr', $taskPushes()[0]['body']);
        $this->assertSame([], array_filter($this->pushesTo($this->trainer), fn ($p) => str_starts_with($p['title'], 'Deine Aufgabe')));
    }

    public function test_no_reminder_for_events_created_shortly_before(): void
    {
        $event = $this->createEvent(['starts_at' => '2026-10-19T18:00', 'ends_at' => '2026-10-19T20:00']);

        $this->artisan('app:send-notifications')->assertSuccessful();

        $this->assertCount(1, $this->pushesTo($this->player));
        $this->assertNotNull($this->inTenant($this->tenant, fn () => Event::find($event['id'])->reminder_sent_at));
        $this->assertSame(0, $this->inTenant($this->tenant, fn () => EventResponse::count()));
    }

    public function test_notices_are_announced_when_they_become_visible(): void
    {
        $this->loginAs($this->trainer)->postJson('/api/v1/t/ssv-ulm/notices', [
            'scope' => 'team', 'team_id' => $this->team->id, 'title' => 'Halle zu', 'body' => 'In den Herbstferien kein Training.',
            'visible_from' => '2026-10-25T00:00', 'visible_until' => '2026-11-08T00:00',
        ])->assertCreated();

        $this->artisan('app:send-notifications')->assertSuccessful();
        $this->assertSame([], $this->pushesTo($this->player));

        Carbon::setTestNow('2026-10-25 08:00:00');
        $this->artisan('app:send-notifications')->assertSuccessful();
        $this->artisan('app:send-notifications')->assertSuccessful();

        $pushes = $this->pushesTo($this->player);
        $this->assertCount(1, $pushes);
        $this->assertSame(['Neue Info: Halle zu', 'In den Herbstferien kein Training.'], [$pushes[0]['title'], $pushes[0]['body']]);
        $this->assertSame([], $this->pushesTo($this->trainer));
        $this->assertNotNull($this->inTenant($this->tenant, fn () => Notice::first()->notified_at));
    }

    public function test_ssl_failure_is_mailed_to_admins_once_per_login(): void
    {
        $this->member($this->tenant, 'ada@example.org', 'Ada', 'Admin', admin: true);
        $other = $this->tenant('tv-other', 'TV Other');
        $this->member($other, 'ada@example.org', 'Ada', 'Admin', admin: true);

        app(CheckSsl::class)->mailAdmins(['host' => 'vb.example.org', 'errors' => ['Zertifikat abgelaufen']]);

        Mail::assertQueued(NotificationMail::class, 1);
        Mail::assertQueued(NotificationMail::class, fn (NotificationMail $m) => $m->hasTo('ada@example.org')
            && $m->body === "Die tägliche Prüfung von vb.example.org hat Fehler gefunden:\n- Zertifikat abgelaufen");
    }

    public function test_parents_and_child_are_told_about_the_release_at_18(): void
    {
        $parent = $this->member($this->tenant, 'eltern@example.org', 'Eva', 'Eltern', gender: 'female');
        $kidPerson = $this->personOf($this->player);
        $this->inTenant($this->tenant, function () use ($parent, $kidPerson) {
            $kidPerson->forceFill(['birthday' => '2008-10-19'])->save();
            PersonAccount::create(['person_id' => $kidPerson->id, 'tenant_member_id' => $parent->id, 'relation' => PersonRelation::Guardian]);
        });

        $this->artisan('app:release-adults')->assertSuccessful();

        Mail::assertQueued(NotificationMail::class, fn (NotificationMail $m) => $m->hasTo('eltern@example.org') && $m->title === 'Paul verwaltet das Profil jetzt selbst');
        Mail::assertQueued(NotificationMail::class, fn (NotificationMail $m) => $m->hasTo('paul@example.org') && $m->title === 'Dein Profil gehört jetzt dir');
    }
}
