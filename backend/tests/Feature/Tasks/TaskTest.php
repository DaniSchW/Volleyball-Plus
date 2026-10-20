<?php

namespace Tests\Feature\Tasks;

use App\Enums\PersonRelation;
use App\Enums\TeamRole;
use App\Models\MinorPermission;
use App\Models\PersonAccount;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\TenantMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class TaskTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Team $team;

    private TenantMember $organizer;

    private TenantMember $paul;

    private TenantMember $mia;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-19 10:00:00');

        $this->tenant = $this->tenant();
        $this->team = $this->team($this->tenant);
        $this->organizer = $this->member($this->tenant, 'olga@example.org', 'Olga', 'Orga', gender: 'female');
        $this->paul = $this->member($this->tenant, 'paul@example.org', 'Paul', 'Spieler');
        $this->mia = $this->member($this->tenant, 'mia@example.org', 'Mia', 'Mit', gender: 'female');
        $this->addToTeam($this->team, $this->personOf($this->organizer), TeamRole::Organizer);
        $this->addToTeam($this->team, $this->personOf($this->paul));
        $this->addToTeam($this->team, $this->personOf($this->mia));
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

    private function event(string $type = 'match', string $day = '2026-10-24'): array
    {
        return $this->loginAs($this->organizer)->postJson($this->url('events'), [
            'type' => $type, 'team_ids' => [$this->team->id], 'title' => $type === 'helper' ? 'Vereinsfest' : null,
            'starts_at' => "{$day}T14:00", 'ends_at' => "{$day}T18:00",
        ])->assertCreated()->json('data');
    }

    private function addTask(string $eventId, string $title, int $slots): array
    {
        return collect($this->loginAs($this->organizer)->postJson($this->url("events/{$eventId}/tasks"), ['title' => $title, 'slots' => $slots])
            ->assertCreated()->json('data.tasks'))->firstWhere('title', $title);
    }

    private function take(TenantMember $as, string $taskId, TenantMember|string $for): TestResponse
    {
        $personId = is_string($for) ? $for : $this->personOf($for)->id;

        return $this->loginAs($as)->postJson($this->url("tasks/{$taskId}/assignments"), ['person_id' => $personId]);
    }

    public function test_organizers_add_tasks_and_players_take_places(): void
    {
        $event = $this->event();
        $task = $this->addTask($event['id'], 'Fahrer', 1);
        $this->loginAs($this->paul)->postJson($this->url("events/{$event['id']}/tasks"), ['title' => 'X'])->assertForbidden();

        $mine = $this->loginAs($this->paul)->getJson($this->url("events/{$event['id']}"))->json('data.tasks.0');
        $this->assertSame([['person_id' => $this->personOf($this->paul)->id, 'name' => 'Paul']], $mine['can_take_for']);

        $tasks = $this->take($this->paul, $task['id'], $this->paul)->assertOk()->json('data.tasks');
        $this->assertSame([['person_id' => $this->personOf($this->paul)->id, 'name' => 'Paul Spieler', 'mine' => true]], $tasks[0]['assignees']);
        // Only one place.
        $this->take($this->mia, $task['id'], $this->mia)->assertUnprocessable()->assertJsonPath('errors.task.0', 'Alle Plätze dieser Aufgabe sind vergeben.');
        // Nobody takes tasks for others – except the organizer.
        $this->take($this->mia, $task['id'], $this->paul)->assertForbidden();
        $this->loginAs($this->mia)->deleteJson($this->url("tasks/{$task['id']}/assignments/{$this->personOf($this->paul)->id}"))->assertForbidden();

        $this->loginAs($this->paul)->deleteJson($this->url("tasks/{$task['id']}/assignments/{$this->personOf($this->paul)->id}"))->assertOk();
        $this->take($this->organizer, $task['id'], $this->mia)->assertOk();

        // Places cannot drop below what is taken.
        $this->loginAs($this->organizer)->patchJson($this->url("tasks/{$task['id']}"), ['slots' => 3])->assertOk();
        $this->take($this->paul, $task['id'], $this->paul)->assertOk();
        $this->loginAs($this->organizer)->patchJson($this->url("tasks/{$task['id']}"), ['slots' => 1])->assertUnprocessable();

        $list = $this->loginAs($this->paul)->getJson($this->url('events'))->json('data.0.task_summary');
        $this->assertSame(['count' => 1, 'slots' => 3, 'open_ended' => 0, 'taken' => 2], $list);
    }

    public function test_children_take_tasks_only_with_their_parents_permission(): void
    {
        $parent = $this->member($this->tenant, 'eltern@example.org', 'Eva', 'Eltern', gender: 'female');
        $kid = $this->member($this->tenant, 'kim@example.org', 'Kim', 'Kind', gender: 'female');
        $kidPerson = $this->personOf($kid);
        $this->inTenant($this->tenant, function () use ($parent, $kidPerson) {
            PersonAccount::create(['person_id' => $kidPerson->id, 'tenant_member_id' => $parent->id, 'relation' => PersonRelation::Guardian]);
            MinorPermission::create(['person_id' => $kidPerson->id, 'own_login' => true, 'can_take_tasks' => false]);
        });
        $this->addToTeam($this->team, $kidPerson);
        $task = $this->addTask($this->event()['id'], 'Kuchen', 5);

        $this->take($kid, $task['id'], $kidPerson->id)->assertForbidden();
        $this->take($parent, $task['id'], $kidPerson->id)->assertOk();
        $this->inTenant($this->tenant, fn () => MinorPermission::query()->update(['can_take_tasks' => true]));
        $this->loginAs($kid)->deleteJson($this->url("tasks/{$task['id']}/assignments/{$kidPerson->id}"))->assertOk();
    }

    public function test_helper_services_need_no_reply_and_closed_events_take_no_helpers(): void
    {
        $helper = $this->event('helper');
        $this->assertSame(['helper', 'info', 'Vereinsfest'], [$helper['type'], $helper['response_mode'], $helper['title']]);
        $kasse = $this->addTask($helper['id'], 'Kasse', 2);
        $this->addTask($helper['id'], 'Grill', 2);
        $this->take($this->paul, $kasse['id'], $this->paul)->assertOk();

        // My tasks page: my task, and the helper service with free places for Mia.
        $mine = $this->loginAs($this->paul)->getJson($this->url('tasks'))->assertOk()->json('data.0');
        $this->assertSame(['Kasse'], array_column($mine['mine'], 'title'));
        $this->assertSame(3, $mine['open_slots']);
        $this->assertSame('Vereinsfest', $this->loginAs($this->mia)->getJson($this->url('tasks'))->json('data.0.event.title'));

        Carbon::setTestNow('2026-10-24 15:00:00');
        $this->take($this->mia, $kasse['id'], $this->mia)->assertUnprocessable();
    }

    public function test_helper_statistics_count_tasks_per_person(): void
    {
        $trainer = $this->member($this->tenant, 'tom@example.org', 'Tom', 'Trainer');
        $this->addToTeam($this->team, $this->personOf($trainer), TeamRole::Trainer);
        $kasse = $this->addTask($this->event('helper')['id'], 'Kasse', 3);
        $fahrer = $this->addTask($this->event('match', '2026-10-31')['id'], 'Fahrer', 3);
        $this->take($this->paul, $kasse['id'], $this->paul)->assertOk();
        $this->take($this->paul, $fahrer['id'], $this->paul)->assertOk();
        $this->take($this->mia, $kasse['id'], $this->mia)->assertOk();

        $url = $this->url("teams/{$this->team->id}/statistics/tasks?from=2026-10-01&to=2026-11-30");
        $rows = $this->loginAs($trainer)->getJson($url)->assertOk()->json('data.rows');
        $this->assertSame([
            ['Paul Spieler', 1, 1, 2],
            ['Mia Mit', 1, 0, 1],
        ], array_map(fn ($r) => [$r['person']['name'], $r['helper'], $r['other'], $r['total']], $rows));

        // Helpers see only their own count, organizers none (no competition).
        $this->assertSame(['Mia Mit'], array_column(array_column($this->loginAs($this->mia)->getJson($url)->json('data.rows'), 'person'), 'name'));
        $this->assertSame([], $this->loginAs($this->organizer)->getJson($url)->json('data.rows'));
    }

    public function test_number_of_people_is_optional(): void
    {
        $event = $this->event('helper');
        $task = collect($this->loginAs($this->organizer)->postJson($this->url("events/{$event['id']}/tasks"), ['title' => 'Kuchen backen'])
            ->assertCreated()->json('data.tasks'))->firstWhere('title', 'Kuchen backen');
        $this->assertNull($task['slots']);
        $this->take($this->paul, $task['id'], $this->paul)->assertOk();
        $this->take($this->mia, $task['id'], $this->mia)->assertOk();
        $this->assertEquals(['count' => 1, 'slots' => 0, 'taken' => 2, 'open_ended' => 1], $this->loginAs($this->paul)->getJson($this->url("events/{$event['id']}"))->json('data.task_summary'));
        // Helper services with unlimited tasks always have room.
        $this->assertSame($event['id'], $this->loginAs($this->organizer)->getJson($this->url('tasks'))->json('data.0.event.id'));
    }

    public function test_task_templates(): void
    {
        $match = $this->event();
        $this->addTask($match['id'], 'Kasse am Eingang', 2);
        $this->addTask($match['id'], 'Linienrichter', 2);

        // Saved from the event; players cannot.
        $this->loginAs($this->paul)->postJson($this->url('task-templates'), ['name' => 'x', 'team_id' => $this->team->id, 'event_id' => $match['id']])->assertForbidden();
        $template = $this->loginAs($this->organizer)->postJson($this->url('task-templates'), ['name' => 'Spieltag Herren 1', 'team_id' => $this->team->id, 'event_id' => $match['id']])
            ->assertCreated()->json('data');
        $this->assertSame([['title' => 'Kasse am Eingang', 'description' => null, 'slots' => 2], ['title' => 'Linienrichter', 'description' => null, 'slots' => 2]], $template['items']);
        // For the whole department only admins.
        $this->loginAs($this->organizer)->postJson($this->url('task-templates'), ['name' => 'Alle', 'items' => [['title' => 'Aufbau']]])->assertForbidden();

        // Edited: more tasks, one without a number.
        $this->loginAs($this->organizer)->patchJson($this->url("task-templates/{$template['id']}"), ['items' => [
            ['title' => 'Kasse am Eingang', 'slots' => 2], ['title' => 'Verkauf', 'slots' => 3], ['title' => 'Punkte zählen', 'slots' => 1], ['title' => 'Aufräumen'],
        ]])->assertOk();

        // Taken over when creating the next match …
        $next = $this->loginAs($this->organizer)->postJson($this->url('events'), [
            'type' => 'match', 'team_ids' => [$this->team->id], 'starts_at' => '2026-11-07T14:00', 'ends_at' => '2026-11-07T18:00', 'task_template_id' => $template['id'],
        ])->assertCreated()->json('data');
        $this->assertSame(['Kasse am Eingang', 'Verkauf', 'Punkte zählen', 'Aufräumen'], array_column($next['tasks'], 'title'));
        $this->assertSame([2, 3, 1, null], array_column($next['tasks'], 'slots'));

        // … and into several existing ones at once; existing titles are skipped.
        $later = $this->event('match', '2026-11-21');
        $this->loginAs($this->organizer)->postJson($this->url("task-templates/{$template['id']}/apply"), ['event_ids' => [$match['id'], $later['id']]])
            ->assertOk()->assertJsonPath('data', ['events' => 2, 'tasks' => 7]);
        $this->assertSame(['Kasse am Eingang', 'Linienrichter', 'Verkauf', 'Punkte zählen', 'Aufräumen'],
            array_column($this->loginAs($this->organizer)->getJson($this->url("events/{$match['id']}"))->json('data.tasks'), 'title'));

        $this->assertSame(['Spieltag Herren 1'], array_column($this->loginAs($this->organizer)->getJson($this->url('task-templates'))->json('data.templates'), 'name'));
        $this->loginAs($this->paul)->getJson($this->url('task-templates'))->assertOk()->assertJsonPath('data.templates', []);
        $this->loginAs($this->organizer)->deleteJson($this->url("task-templates/{$template['id']}"))->assertOk();
    }
}
