<?php

namespace Tests\Feature\Polls;

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

class PollTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Team $team;

    private Team $other;

    private TenantMember $admin;

    private TenantMember $trainer;

    private TenantMember $paul;

    private TenantMember $mia;

    private TenantMember $lena;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-19 10:00:00');
        Queue::fake();

        $this->tenant = $this->tenant();
        $this->team = $this->team($this->tenant);
        $this->other = $this->team($this->tenant, 'Damen 1', 'women');
        $this->admin = $this->member($this->tenant, 'ada@example.org', 'Ada', 'Admin', admin: true, gender: 'female');
        $this->trainer = $this->member($this->tenant, 'tom@example.org', 'Tom', 'Trainer');
        $this->paul = $this->member($this->tenant, 'paul@example.org', 'Paul', 'Spieler');
        $this->mia = $this->member($this->tenant, 'mia@example.org', 'Mia', 'Mit', gender: 'female');
        $this->lena = $this->member($this->tenant, 'lena@example.org', 'Lena', 'Damen', gender: 'female');
        $this->addToTeam($this->team, $this->personOf($this->trainer), TeamRole::Trainer);
        $this->addToTeam($this->team, $this->personOf($this->paul));
        $this->addToTeam($this->team, $this->personOf($this->mia));
        $this->addToTeam($this->other, $this->personOf($this->lena));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function create(TenantMember $as, array $data = []): TestResponse
    {
        return $this->loginAs($as)->postJson('/api/v1/t/ssv-ulm/polls', $data + [
            'title' => 'Wann grillen wir?', 'scope' => 'team', 'team_ids' => [$this->team->id],
            'options' => ['Samstag', 'Sonntag'],
        ]);
    }

    private function vote(TenantMember $as, string $pollId, TenantMember|string $for, array $optionIds, ?string $text = null): TestResponse
    {
        return $this->loginAs($as)->postJson("/api/v1/t/ssv-ulm/polls/{$pollId}/votes", [
            'person_id' => is_string($for) ? $for : $this->personOf($for)->id, 'option_ids' => $optionIds, 'free_text' => $text,
        ]);
    }

    public function test_team_poll_results_after_voting(): void
    {
        $poll = $this->create($this->trainer)->assertCreated()->json('data');
        [$sat, $sun] = array_column($poll['options'], 'id');
        $this->create($this->paul)->assertForbidden();

        // Announced to the team, not to the creator nor other teams.
        $this->assertCount(2, Queue::pushed(SendPush::class));
        $this->assertSame('Neue Umfrage: Wann grillen wir?', Queue::pushed(SendPush::class)->first()->payload['title']);

        // Before voting no results.
        $before = $this->loginAs($this->paul)->getJson("/api/v1/t/ssv-ulm/polls/{$poll['id']}")->json('data');
        $this->assertFalse($before['results_visible']);
        $this->assertNull($before['options'][0]['count']);

        $this->vote($this->paul, $poll['id'], $this->paul, [$sat, $sun])->assertUnprocessable(); // single choice
        $after = $this->vote($this->paul, $poll['id'], $this->paul, [$sat])->assertOk()->json('data');
        $this->assertSame([1, ['Paul Spieler']], [$after['options'][0]['count'], $after['options'][0]['voters']]);
        $this->assertSame([$sat], $after['my'][0]['option_ids']);

        // Changing the vote replaces it.
        $after = $this->vote($this->paul, $poll['id'], $this->paul, [$sun])->json('data');
        $this->assertSame([0, 1], array_column($after['options'], 'count'));

        // Others outside the team neither see nor vote.
        $this->loginAs($this->lena)->getJson("/api/v1/t/ssv-ulm/polls/{$poll['id']}")->assertForbidden();
        $this->assertSame([], $this->loginAs($this->lena)->getJson('/api/v1/t/ssv-ulm/polls')->json('data'));
        $this->vote($this->mia, $poll['id'], $this->paul, [$sat])->assertForbidden();
    }

    public function test_admins_ask_all_teams_anonymously_with_free_text(): void
    {
        $this->create($this->trainer, ['scope' => 'tenant'])->assertForbidden();
        $poll = $this->create($this->admin, [
            'scope' => 'tenant', 'title' => 'Neue Trikots?', 'options' => ['Blau', 'Rot', 'Weiß'],
            'multiple_choice' => true, 'anonymous' => true, 'allow_free_text' => true,
        ])->assertCreated()->json('data');
        $this->assertCount(2, $poll['teams']);
        [$blue, $red] = array_column($poll['options'], 'id');

        $this->vote($this->lena, $poll['id'], $this->lena, [$blue, $red], 'Mit Vereinswappen')->assertOk();
        $data = $this->vote($this->paul, $poll['id'], $this->paul, [], 'Egal')->assertOk()->json('data');

        $this->assertSame([1, 1, 0], array_column($data['options'], 'count'));
        $this->assertNull($data['options'][0]['voters']);
        $this->assertEqualsCanonicalizing([['text' => 'Mit Vereinswappen', 'name' => null], ['text' => 'Egal', 'name' => null]], $data['free_texts']);
        $this->assertSame(2, $data['voter_count']);
        $this->vote($this->mia, $poll['id'], $this->mia, [])->assertUnprocessable();
    }

    public function test_closing_and_deadline(): void
    {
        $poll = $this->create($this->trainer, ['closes_at' => '2026-10-20T18:00'])->json('data');
        $this->assertSame('2026-10-20T16:00:00Z', $poll['closes_at']);
        $sat = $poll['options'][0]['id'];

        Carbon::setTestNow('2026-10-20 16:30:00');
        $this->vote($this->paul, $poll['id'], $this->paul, [$sat])->assertUnprocessable();
        // After the end everybody sees the result.
        $this->assertTrue($this->loginAs($this->mia)->getJson("/api/v1/t/ssv-ulm/polls/{$poll['id']}")->json('data.results_visible'));

        $open = $this->create($this->trainer)->json('data');
        $this->loginAs($this->paul)->postJson("/api/v1/t/ssv-ulm/polls/{$open['id']}/close")->assertForbidden();
        $this->loginAs($this->trainer)->postJson("/api/v1/t/ssv-ulm/polls/{$open['id']}/close")->assertOk()->assertJsonPath('data.is_closed', true);
        $this->loginAs($this->trainer)->deleteJson("/api/v1/t/ssv-ulm/polls/{$open['id']}")->assertOk();
        $this->assertCount(1, $this->loginAs($this->paul)->getJson('/api/v1/t/ssv-ulm/polls')->json('data'));
    }

    public function test_parents_vote_for_children_unless_the_child_may(): void
    {
        $parent = $this->member($this->tenant, 'eltern@example.org', 'Eva', 'Eltern', gender: 'female');
        $kid = $this->member($this->tenant, 'kim@example.org', 'Kim', 'Kind', gender: 'female');
        $kidPerson = $this->personOf($kid);
        $this->inTenant($this->tenant, function () use ($parent, $kidPerson) {
            PersonAccount::create(['person_id' => $kidPerson->id, 'tenant_member_id' => $parent->id, 'relation' => PersonRelation::Guardian]);
            MinorPermission::create(['person_id' => $kidPerson->id, 'own_login' => true]);
        });
        $this->addToTeam($this->team, $kidPerson);
        $poll = $this->create($this->trainer)->json('data');

        $mine = $this->loginAs($parent)->getJson("/api/v1/t/ssv-ulm/polls/{$poll['id']}")->json('data.my');
        $this->assertSame([['Kim', true]], array_map(fn ($m) => [$m['name'], $m['can_vote']], $mine));
        $this->assertFalse($this->loginAs($kid)->getJson("/api/v1/t/ssv-ulm/polls/{$poll['id']}")->json('data.my.0.can_vote'));
        $this->vote($kid, $poll['id'], $kidPerson->id, [$poll['options'][0]['id']])->assertForbidden();
        $this->vote($parent, $poll['id'], $kidPerson->id, [$poll['options'][0]['id']])->assertOk();
    }
}
