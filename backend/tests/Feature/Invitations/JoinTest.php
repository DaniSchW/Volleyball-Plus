<?php

namespace Tests\Feature\Invitations;

use App\Enums\PersonRelation;
use App\Enums\TeamRole;
use App\Mail\MagicLinkMail;
use App\Models\Account;
use App\Models\Invitation;
use App\Models\InvitationUse;
use App\Models\MinorPermission;
use App\Models\Person;
use App\Models\PersonAccount;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\Tenant;
use App\Models\TenantMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class JoinTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Team $team;

    private TenantMember $trainer;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->tenant = $this->tenant();
        $this->team = $this->team($this->tenant);
        $this->trainer = $this->member($this->tenant, 'trainer@example.org', 'Tina', 'Trainer', gender: 'female');
        $this->addToTeam($this->team, $this->personOf($this->trainer), TeamRole::Trainer);
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function createLink(TenantMember $as, string $role = 'player', array $extra = []): array
    {
        $data = $this->loginAs($as)
            ->postJson("/api/v1/t/ssv-ulm/teams/{$this->team->id}/invitations", ['role' => $role] + $extra)
            ->assertCreated()
            ->json('data');

        $this->assertStringStartsWith('https://localhost/ssv-ulm/join/', $data['url']);

        return [substr($data['url'], strrpos($data['url'], '/') + 1), $data];
    }

    private function logout(): void
    {
        $this->withCredentials(false)->defaultCookies = [];
        $this->unencryptedCookies = [];
    }

    /** Confirms the address via the mailed link and returns the response. */
    private function confirmMail(): TestResponse
    {
        $url = null;
        Mail::assertSent(MagicLinkMail::class, function (MagicLinkMail $mail) use (&$url) {
            $url = $mail->url;

            return true;
        });

        return $this->postJson('/api/v1/auth/consume', ['token' => substr($url, strpos($url, 'token=') + 6)]);
    }

    public function test_new_player_joins_after_confirming_the_address(): void
    {
        [$token] = $this->createLink($this->trainer);
        $this->logout();

        $this->getJson("/api/v1/t/ssv-ulm/invitations/{$token}")
            ->assertOk()
            ->assertJsonPath('data.team', 'Herren 1')
            ->assertJsonPath('data.allows_child', true);

        $this->postJson("/api/v1/t/ssv-ulm/invitations/{$token}/accept", [
            'mode' => 'self', 'email' => 'Neu@Example.org', 'first_name' => 'Nico', 'last_name' => 'Neu', 'gender' => 'male',
        ])->assertStatus(202)->assertJsonPath('data.status', 'confirm');

        // Nothing is created before the address is confirmed.
        $this->assertNull(Account::where('email', 'neu@example.org')->first());

        $this->confirmMail()->assertOk()->assertJsonPath('data.tenant', 'ssv-ulm');

        $this->inTenant($this->tenant, function () {
            $member = TenantMember::where('account_id', Account::where('email', 'neu@example.org')->value('id'))->firstOrFail();
            $person = $member->ownPerson();
            $this->assertSame('Nico', $person->first_name);
            $this->assertTrue(TeamMembership::where('team_id', $this->team->id)->where('person_id', $person->id)->where('role', 'player')->exists());
            $this->assertSame(1, Invitation::first()->use_count);
            $this->assertSame(1, InvitationUse::count());
        });
    }

    public function test_parent_registers_a_child(): void
    {
        [$token] = $this->createLink($this->trainer);
        $this->logout();

        $this->postJson("/api/v1/t/ssv-ulm/invitations/{$token}/accept", [
            'mode' => 'child', 'email' => 'eltern@example.org',
            'child_first_name' => 'Kim', 'child_last_name' => 'Klein', 'child_gender' => 'female',
            'child_birthday' => Carbon::today()->subYears(12)->toDateString(),
        ])->assertStatus(202);
        $this->confirmMail()->assertOk();

        $this->inTenant($this->tenant, function () {
            $parent = TenantMember::where('account_id', Account::where('email', 'eltern@example.org')->value('id'))->firstOrFail();
            $child = Person::where('first_name', 'Kim')->firstOrFail();

            $this->assertTrue(PersonAccount::where('person_id', $child->id)->where('tenant_member_id', $parent->id)->where('relation', 'guardian')->exists());
            $this->assertTrue(TeamMembership::where('person_id', $child->id)->where('team_id', $this->team->id)->exists());
            $this->assertFalse(MinorPermission::where('person_id', $child->id)->firstOrFail()->own_login);
            // The parent has no own profile and is not a player.
            $this->assertNull($parent->ownPerson());
        });
    }

    public function test_child_registration_only_for_minors(): void
    {
        [$token] = $this->createLink($this->trainer);
        $this->logout();

        $this->postJson("/api/v1/t/ssv-ulm/invitations/{$token}/accept", [
            'mode' => 'child', 'email' => 'eltern@example.org',
            'child_first_name' => 'Kim', 'child_last_name' => 'Klein', 'child_gender' => 'female',
            'child_birthday' => Carbon::today()->subYears(18)->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('child_birthday');
    }

    public function test_logged_in_member_joins_directly_with_existing_profile(): void
    {
        $player = $this->member($this->tenant, 'spieler@example.org', 'Paul', 'Spieler');
        $second = $this->team($this->tenant, 'Herren 2');
        $this->addToTeam($second, $this->personOf($this->trainer), TeamRole::Trainer);
        $token = substr($this->loginAs($this->trainer)
            ->postJson("/api/v1/t/ssv-ulm/teams/{$second->id}/invitations", ['role' => 'player'])
            ->json('data.url'), -32);

        $this->loginAs($player)
            ->postJson("/api/v1/t/ssv-ulm/invitations/{$token}/accept", ['mode' => 'self'])
            ->assertOk()
            ->assertJsonPath('data.status', 'joined');

        Mail::assertNothingSent();
        $this->inTenant($this->tenant, function () use ($player, $second) {
            $this->assertSame(1, Person::where('first_name', 'Paul')->count());
            $this->assertTrue(TeamMembership::where('team_id', $second->id)->where('person_id', $player->ownPerson()->id)->exists());
        });
    }

    public function test_limits_expiry_and_revocation(): void
    {
        $player = $this->member($this->tenant, 'spieler@example.org', 'Paul', 'Spieler');

        [$limited] = $this->createLink($this->trainer, 'player', ['max_uses' => 1]);
        $this->loginAs($player)->postJson("/api/v1/t/ssv-ulm/invitations/{$limited}/accept", ['mode' => 'self'])->assertOk();
        $this->getJson("/api/v1/t/ssv-ulm/invitations/{$limited}")->assertNotFound();

        [$expiring] = $this->createLink($this->trainer, 'player', ['expires_in_days' => 1]);
        Carbon::setTestNow(Carbon::now()->addDays(2));
        $this->getJson("/api/v1/t/ssv-ulm/invitations/{$expiring}")->assertNotFound();
        Carbon::setTestNow();

        [$revoked, $data] = $this->createLink($this->trainer);
        $this->deleteJson("/api/v1/t/ssv-ulm/invitations/{$data['id']}")->assertOk();
        $this->getJson("/api/v1/t/ssv-ulm/invitations/{$revoked}")->assertNotFound();
    }

    public function test_links_only_work_in_their_department(): void
    {
        [$token] = $this->createLink($this->trainer);
        $this->tenant('tsv-anders', 'TSV Anders');

        $this->getJson("/api/v1/t/tsv-anders/invitations/{$token}")->assertNotFound();
    }

    public function test_who_may_create_links(): void
    {
        $organizer = $this->member($this->tenant, 'orga@example.org', 'Olga', 'Orga', gender: 'female');
        $this->addToTeam($this->team, $this->personOf($organizer), TeamRole::Organizer);
        $player = $this->member($this->tenant, 'spieler@example.org', 'Paul', 'Spieler');
        $this->addToTeam($this->team, $this->personOf($player));

        $url = "/api/v1/t/ssv-ulm/teams/{$this->team->id}/invitations";
        $this->loginAs($organizer)->postJson($url, ['role' => 'player'])->assertCreated();
        $this->loginAs($organizer)->postJson($url, ['role' => 'trainer'])->assertForbidden();
        $this->loginAs($player)->postJson($url, ['role' => 'player'])->assertForbidden();
        $this->loginAs($this->trainer)->postJson($url, ['role' => 'trainer'])->assertCreated();

        // Links can be shown again for copying.
        $links = $this->loginAs($this->trainer)->getJson($url)->assertOk()->json('data');
        $this->assertCount(2, $links);
        $this->assertStringStartsWith('https://localhost/ssv-ulm/join/', $links[0]['url']);
    }

    public function test_parent_is_connected_to_a_child_profile_created_by_the_trainer(): void
    {
        $childId = $this->loginAs($this->trainer)->postJson('/api/v1/t/ssv-ulm/persons', [
            'team_id' => $this->team->id, 'role' => 'player',
            'first_name' => 'Kim', 'last_name' => 'Klein', 'gender' => 'female', 'birthday' => '2014-03-01',
        ])->assertCreated()->json('data.id');

        $url = $this->postJson("/api/v1/t/ssv-ulm/persons/{$childId}/invitations", ['relation' => 'guardian'])
            ->assertCreated()->json('data.url');
        $token = substr($url, strrpos($url, '/') + 1);
        $this->logout();

        $this->getJson("/api/v1/t/ssv-ulm/invitations/{$token}")->assertJsonPath('data.person', 'Kim Klein');
        $this->postJson("/api/v1/t/ssv-ulm/invitations/{$token}/accept", ['email' => 'eltern@example.org'])->assertStatus(202);
        $this->confirmMail()->assertOk();

        $this->inTenant($this->tenant, function () use ($childId) {
            $this->assertTrue(PersonAccount::where('person_id', $childId)->where('relation', PersonRelation::Guardian)->exists());
        });
        // Single use.
        $this->getJson("/api/v1/t/ssv-ulm/invitations/{$token}")->assertNotFound();
    }

    public function test_only_parents_invite_a_child_to_its_own_login(): void
    {
        $parent = $this->member($this->tenant, 'eltern@example.org', 'Eva', 'Eltern', gender: 'female');
        $childId = $this->inTenant($this->tenant, function () use ($parent) {
            $child = Person::create(['first_name' => 'Kim', 'last_name' => 'Eltern', 'gender' => 'female', 'birthday' => '2012-01-01']);
            PersonAccount::create(['person_id' => $child->id, 'tenant_member_id' => $parent->id, 'relation' => PersonRelation::Guardian]);
            MinorPermission::create(['person_id' => $child->id]);
            TeamMembership::create(['team_id' => $this->team->id, 'person_id' => $child->id, 'role' => TeamRole::Player]);

            return $child->id;
        });

        $this->loginAs($this->trainer)->postJson("/api/v1/t/ssv-ulm/persons/{$childId}/invitations", ['relation' => 'self'])->assertForbidden();

        $url = $this->loginAs($parent)->postJson("/api/v1/t/ssv-ulm/persons/{$childId}/invitations", ['relation' => 'self'])
            ->assertCreated()->json('data.url');
        $this->logout();

        $this->postJson('/api/v1/t/ssv-ulm/invitations/'.substr($url, strrpos($url, '/') + 1).'/accept', ['email' => 'kim@example.org'])->assertStatus(202);
        $this->withSessionFrom($this->confirmMail()->assertOk());

        $this->inTenant($this->tenant, function () use ($childId) {
            $this->assertTrue(MinorPermission::where('person_id', $childId)->value('own_login'));
            $this->assertTrue(PersonAccount::where('person_id', $childId)->where('relation', PersonRelation::Own)->exists());
        });
        // The child can now use the app.
        $this->getJson('/api/v1/t/ssv-ulm/me')->assertOk();
    }

    public function test_admins_invite_further_admins_into_the_club(): void
    {
        $admin = $this->member($this->tenant, 'ada@example.org', 'Ada', 'Admin', admin: true, gender: 'female');
        $this->loginAs($this->trainer)->postJson('/api/v1/t/ssv-ulm/department-invitations', ['admin' => true])->assertForbidden();

        $link = $this->loginAs($admin)->postJson('/api/v1/t/ssv-ulm/department-invitations', ['admin' => true, 'max_uses' => 1])->assertCreated()->json('data');
        $this->assertTrue($link['grants_admin']);
        $this->assertSame([$link['id']], array_column($this->loginAs($admin)->getJson('/api/v1/t/ssv-ulm/department-invitations')->json('data'), 'id'));
        $token = substr($link['url'], strrpos($link['url'], '/') + 1);
        $this->logout();

        $this->getJson("/api/v1/t/ssv-ulm/invitations/{$token}")->assertOk()->assertJsonPath('data.kind', 'department')->assertJsonPath('data.grants_admin', true);
        $this->postJson("/api/v1/t/ssv-ulm/invitations/{$token}/accept", ['email' => 'bernd@example.org'])->assertUnprocessable()->assertJsonValidationErrors(['first_name', 'gender']);
        $this->postJson("/api/v1/t/ssv-ulm/invitations/{$token}/accept", [
            'email' => 'bernd@example.org', 'first_name' => 'Bernd', 'last_name' => 'Boss', 'gender' => 'male',
        ])->assertStatus(202);
        $this->confirmMail()->assertOk();

        $bernd = $this->inTenant($this->tenant, fn () => TenantMember::query()->whereHas('account', fn ($q) => $q->where('email', 'bernd@example.org'))->firstOrFail());
        $this->assertTrue($bernd->is_admin);
        // Same rights as every admin: e.g. take away the first admin's role.
        $this->loginAs($bernd)->patchJson("/api/v1/t/ssv-ulm/members/{$admin->id}", ['is_admin' => false])->assertOk();
        // The link was for one person.
        $this->assertSame([], $this->loginAs($bernd)->getJson('/api/v1/t/ssv-ulm/department-invitations')->json('data'));
    }
}
