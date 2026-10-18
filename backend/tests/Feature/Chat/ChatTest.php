<?php

namespace Tests\Feature\Chat;

use App\Enums\PersonRelation;
use App\Enums\TeamRole;
use App\Jobs\SendPush;
use App\Models\MinorPermission;
use App\Models\PersonAccount;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\TenantMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Team $team;

    private TenantMember $admin;

    private TenantMember $trainer;

    private TenantMember $paul;

    private TenantMember $lena;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-19 10:00:00');
        Queue::fake();

        $this->tenant = $this->tenant();
        $this->team = $this->team($this->tenant);
        $other = $this->team($this->tenant, 'Damen 1', 'women');
        $this->admin = $this->member($this->tenant, 'ada@example.org', 'Ada', 'Admin', admin: true, gender: 'female');
        $this->trainer = $this->member($this->tenant, 'tom@example.org', 'Tom', 'Trainer');
        $this->paul = $this->member($this->tenant, 'paul@example.org', 'Paul', 'Spieler');
        $this->lena = $this->member($this->tenant, 'lena@example.org', 'Lena', 'Damen', gender: 'female');
        $this->addToTeam($this->team, $this->personOf($this->trainer), TeamRole::Trainer);
        $this->addToTeam($this->team, $this->personOf($this->paul));
        $this->addToTeam($other, $this->personOf($this->lena));
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

    private function teamChat(TenantMember $as): ?array
    {
        return collect($this->loginAs($as)->getJson($this->url('chats'))->assertOk()->json('data'))->firstWhere('name', 'Herren 1');
    }

    private function send(TenantMember $as, string $chatId, string $body, array $extra = []): TestResponse
    {
        return $this->loginAs($as)->postJson($this->url("chats/{$chatId}/messages"), ['body' => $body] + $extra);
    }

    public function test_every_team_has_a_chat_for_its_people(): void
    {
        $chat = $this->teamChat($this->paul);
        $this->assertSame(['team', 'flat'], [$chat['kind'], $chat['mode']]);
        $this->assertSame(['Damen 1'], array_column($this->loginAs($this->lena)->getJson($this->url('chats'))->json('data'), 'name'));

        $this->send($this->paul, $chat['id'], 'Wer hat den Hallenschlüssel?')->assertCreated()
            ->assertJsonPath('data.author.name', 'Paul Spieler')->assertJsonPath('data.is_mine', true);
        $this->loginAs($this->lena)->getJson($this->url("chats/{$chat['id']}/messages"))->assertForbidden();
        $this->send($this->lena, $chat['id'], 'Hallo?')->assertForbidden();

        // Admins can open every chat.
        $this->loginAs($this->admin)->getJson($this->url("chats/{$chat['id']}/messages"))->assertOk()->assertJsonCount(1, 'data');
        $members = $this->loginAs($this->trainer)->getJson($this->url("chats/{$chat['id']}"))->json('data.members');
        $this->assertSame(['Paul Spieler', 'Tom Trainer'], array_column($members, 'name'));
    }

    public function test_unread_polling_edit_delete_and_reply(): void
    {
        $chat = $this->teamChat($this->paul);
        $first = $this->send($this->trainer, $chat['id'], 'Training fällt aus')->json('data');
        $this->send($this->trainer, $chat['id'], 'Halle gesperrt')->assertCreated();
        $this->assertSame(2, $this->teamChat($this->paul)['unread']);
        $this->assertSame('Halle gesperrt', $this->teamChat($this->paul)['last_message']['body']);
        $this->assertSame(0, $this->teamChat($this->trainer)['unread']);

        // Opening the chat marks it read.
        $page = $this->loginAs($this->paul)->getJson($this->url("chats/{$chat['id']}/messages"))->assertOk()->json();
        $this->assertSame(['Training fällt aus', 'Halle gesperrt'], array_column($page['data'], 'body'));
        $this->assertSame(0, $this->teamChat($this->paul)['unread']);

        // Polling: a reply arrives, an earlier message gets edited and one deleted.
        $last = end($page['data'])['id'];
        Carbon::setTestNow('2026-10-19 10:00:30');
        $reply = $this->send($this->paul, $chat['id'], 'Schade!', ['reply_to_id' => $first['id']])->json('data');
        $this->assertSame(['author' => 'Tom Trainer', 'body' => 'Training fällt aus'], array_diff_key($reply['reply_to'], ['id' => 1]));
        $this->loginAs($this->trainer)->patchJson($this->url("chat-messages/{$first['id']}"), ['body' => 'Training fällt heute aus'])->assertOk();
        $this->loginAs($this->paul)->patchJson($this->url("chat-messages/{$first['id']}"), ['body' => 'Hack'])->assertForbidden();

        $poll = $this->loginAs($this->paul)->getJson($this->url("chats/{$chat['id']}/messages?after={$last}&since=".urlencode($page['server_time'])))->json();
        $this->assertSame(['Schade!'], array_column($poll['data'], 'body'));
        $this->assertSame([['Training fällt heute aus', true]], array_map(fn ($m) => [$m['body'], $m['edited']], $poll['changed']));

        // Players delete only their own; trainers (moderators) any.
        $this->loginAs($this->paul)->deleteJson($this->url("chat-messages/{$first['id']}"))->assertForbidden();
        $this->loginAs($this->trainer)->deleteJson($this->url("chat-messages/{$reply['id']}"))->assertOk()->assertJsonPath('data.deleted', true)->assertJsonPath('data.body', null);
    }

    public function test_push_goes_to_others_who_are_not_looking_right_now(): void
    {
        $chat = $this->teamChat($this->paul);
        $this->loginAs($this->trainer)->getJson($this->url("chats/{$chat['id']}/messages"))->assertOk();

        $this->send($this->paul, $chat['id'], 'Bin heute 10 Minuten später da')->assertCreated();
        $this->assertSame([], Queue::pushed(SendPush::class)->all(), 'trainer has the chat open');

        Carbon::setTestNow('2026-10-19 10:05:00');
        $this->send($this->paul, $chat['id'], 'Doch pünktlich')->assertCreated();
        $push = Queue::pushed(SendPush::class)->first();
        $this->assertSame($this->trainer->account_id, $push->accountId);
        $this->assertSame(['Herren 1', 'Paul Spieler: Doch pünktlich'], [$push->payload['title'], $push->payload['body']]);
        $this->assertCount(1, Queue::pushed(SendPush::class));
    }

    public function test_parents_chat_for_children_and_children_only_with_permission(): void
    {
        $parent = $this->member($this->tenant, 'eltern@example.org', 'Eva', 'Eltern', gender: 'female');
        $kid = $this->member($this->tenant, 'kim@example.org', 'Kim', 'Kind', gender: 'female');
        $kidPerson = $this->personOf($kid);
        $this->inTenant($this->tenant, function () use ($parent, $kidPerson) {
            PersonAccount::create(['person_id' => $kidPerson->id, 'tenant_member_id' => $parent->id, 'relation' => PersonRelation::Guardian]);
            MinorPermission::create(['person_id' => $kidPerson->id, 'own_login' => true, 'can_chat' => false]);
        });
        $this->addToTeam($this->team, $kidPerson);
        $chat = $this->teamChat($this->paul);

        $this->send($parent, $chat['id'], 'Kim ist krank')->assertCreated()->assertJsonPath('data.author.name', 'Eva Eltern · Kim');
        $this->send($kid, $chat['id'], 'Hallo')->assertForbidden();
        $this->assertNull($this->teamChat($kid));

        $this->inTenant($this->tenant, fn () => MinorPermission::query()->update(['can_chat' => true]));
        $this->send($kid, $chat['id'], 'Wieder gesund')->assertCreated();
    }

    public function test_admins_create_custom_chats_with_topics(): void
    {
        $board = [$this->trainer->id, $this->lena->id];
        $this->loginAs($this->trainer)->postJson($this->url('chats'), ['name' => 'Vorstand', 'member_ids' => $board])->assertForbidden();
        $chat = $this->loginAs($this->admin)->postJson($this->url('chats'), ['name' => 'Abteilungsleitung', 'member_ids' => $board, 'mode' => 'topics'])
            ->assertOk()->json('data');
        $this->assertSame(['custom', 'topics', ['Allgemein']], [$chat['kind'], $chat['mode'], array_column($chat['topics'], 'name')]);
        $this->assertSame(['Ada Admin', 'Lena Damen', 'Tom Trainer'], array_column($chat['members'], 'name'));
        $this->assertNull(collect($this->loginAs($this->paul)->getJson($this->url('chats'))->json('data'))->firstWhere('name', 'Abteilungsleitung'));

        $general = $chat['topics'][0]['id'];
        $chat = $this->loginAs($this->admin)->postJson($this->url("chats/{$chat['id']}/topics"), ['name' => 'Finanzen'])->json('data');
        $finance = collect($chat['topics'])->firstWhere('name', 'Finanzen')['id'];

        $this->send($this->lena, $chat['id'], 'Ohne Thema')->assertUnprocessable()->assertJsonValidationErrors('topic_id');
        $this->send($this->lena, $chat['id'], 'Beitrag erhöhen?', ['topic_id' => $finance])->assertCreated();
        $this->send($this->lena, $chat['id'], 'Hallo zusammen', ['topic_id' => $general])->assertCreated();

        $show = $this->loginAs($this->trainer)->getJson($this->url("chats/{$chat['id']}"))->json('data');
        $this->assertSame([1, 1], array_column($show['topics'], 'unread'));
        $this->assertSame(['Beitrag erhöhen?'], array_column($this->loginAs($this->trainer)->getJson($this->url("chats/{$chat['id']}/messages?topic={$finance}"))->json('data'), 'body'));
        $this->assertSame(1, collect($this->loginAs($this->trainer)->getJson($this->url('chats'))->json('data'))->firstWhere('name', 'Abteilungsleitung')['unread']);

        // Team chat switched to topics: earlier messages land in "Allgemein".
        $team = $this->teamChat($this->trainer);
        $this->send($this->paul, $team['id'], 'Erste Nachricht')->assertCreated();
        $this->loginAs($this->paul)->patchJson($this->url("chats/{$team['id']}"), ['mode' => 'topics'])->assertForbidden();
        $team = $this->loginAs($this->trainer)->patchJson($this->url("chats/{$team['id']}"), ['mode' => 'topics'])->assertOk()->json('data');
        $this->assertSame(['Erste Nachricht'], array_column($this->loginAs($this->paul)->getJson($this->url("chats/{$team['id']}/messages?topic={$team['topics'][0]['id']}"))->json('data'), 'body'));

        $this->loginAs($this->admin)->deleteJson($this->url("chats/{$chat['id']}"))->assertOk();
        $this->loginAs($this->trainer)->getJson($this->url("chats/{$chat['id']}"))->assertNotFound();
    }
}
