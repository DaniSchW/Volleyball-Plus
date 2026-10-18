<?php

namespace Tests\Feature\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\TenantMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private TenantMember $admin;

    private TenantMember $trainer;

    private TenantMember $player;

    private TenantMember $outsider;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->tenant();
        $this->admin = $this->member($this->tenant, 'admin@example.org', 'Ada', 'Admin', admin: true, gender: 'female');
        $this->trainer = $this->member($this->tenant, 'trainer@example.org', 'Tom', 'Trainer');
        $this->player = $this->member($this->tenant, 'spieler@example.org', 'Paul', 'Spieler');
        $this->outsider = $this->member($this->tenant, 'andere@example.org', 'Otto', 'Anders');
        $this->team = $this->team($this->tenant);
        $this->addToTeam($this->team, $this->personOf($this->trainer), TeamRole::Trainer);
        $this->addToTeam($this->team, $this->personOf($this->player));
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/t/ssv-ulm/teams/{$this->team->id}{$suffix}";
    }

    public function test_only_admins_create_and_archive_teams(): void
    {
        $this->loginAs($this->trainer)->postJson('/api/v1/t/ssv-ulm/teams', ['name' => 'Damen 1', 'gender' => 'women'])->assertForbidden();

        $id = $this->loginAs($this->admin)->postJson('/api/v1/t/ssv-ulm/teams', ['name' => 'Damen 1', 'gender' => 'women', 'is_youth' => false])
            ->assertCreated()->assertJsonPath('data.settings.rsvp_visibility', 'names')->json('data.id');

        $this->deleteJson("/api/v1/t/ssv-ulm/teams/{$id}")->assertOk();
        $names = array_column($this->getJson('/api/v1/t/ssv-ulm/teams')->json('data'), 'name');
        $this->assertSame(['Herren 1'], $names);
    }

    public function test_trainer_changes_settings_but_not_base_data(): void
    {
        $this->loginAs($this->trainer)->patchJson($this->url(), [
            'rsvp_visibility' => 'counts',
            'decline_reason_required' => true,
            'reasons_visible_to_teammates' => false,
            'cross_nomination_mode' => 'hours_before_start',
            'cross_nomination_hours' => 24,
            'min_participants' => 8,
        ])->assertOk()
            ->assertJsonPath('data.settings.rsvp_visibility', 'counts')
            ->assertJsonPath('data.settings.decline_reason_required', true)
            ->assertJsonPath('data.settings.cross_nomination_hours', 24);

        $this->patchJson($this->url(), ['name' => 'Umbenannt'])->assertForbidden();
        $this->loginAs($this->admin)->patchJson($this->url(), ['name' => 'Herren I'])->assertOk()->assertJsonPath('data.name', 'Herren I');
    }

    public function test_players_and_others_cannot_change_teams(): void
    {
        $this->loginAs($this->player)->patchJson($this->url(), ['rsvp_visibility' => 'counts'])->assertForbidden();
        $this->loginAs($this->outsider)->patchJson($this->url(), ['rsvp_visibility' => 'counts'])->assertForbidden();
    }

    public function test_roster_is_visible_to_team_members_and_admins_only(): void
    {
        $rows = $this->loginAs($this->player)->getJson($this->url('/members'))->assertOk()->json('data');
        $this->assertSame(['Spieler', 'Trainer'], array_column(array_column($rows, 'person'), 'last_name'));
        $this->assertSame('trainer', $rows[1]['roles'][0]['role']);

        $this->loginAs($this->admin)->getJson($this->url('/members'))->assertOk();
        $this->loginAs($this->outsider)->getJson($this->url('/members'))->assertForbidden();
    }

    public function test_trainer_adds_and_removes_people(): void
    {
        $outsiderPerson = $this->personOf($this->outsider);

        $id = $this->loginAs($this->trainer)
            ->postJson($this->url('/members'), ['person_id' => $outsiderPerson->id, 'role' => 'organizer'])
            ->assertCreated()->json('data.id');

        $this->assertCount(3, $this->getJson($this->url('/members'))->json('data'));

        $this->deleteJson($this->url("/members/{$id}"))->assertOk();
        $this->assertCount(2, $this->getJson($this->url('/members'))->json('data'));

        $this->loginAs($this->player)->postJson($this->url('/members'), ['person_id' => $outsiderPerson->id, 'role' => 'player'])->assertForbidden();
    }

    public function test_teams_of_other_departments_are_not_found(): void
    {
        $other = $this->tenant('tsv-anders', 'TSV Anders');
        $foreignTeam = $this->team($other, 'Fremd');
        $foreignMember = $this->member($other, 'fremd@example.org');
        $foreignPerson = $this->personOf($foreignMember);

        $this->loginAs($this->admin)->getJson("/api/v1/t/ssv-ulm/teams/{$foreignTeam->id}")->assertNotFound();
        $this->getJson("/api/v1/t/ssv-ulm/persons/{$foreignPerson->id}")->assertNotFound();
        // Nor can a foreign person be put into an own team.
        $this->postJson($this->url('/members'), ['person_id' => $foreignPerson->id, 'role' => 'player'])->assertStatus(422);
    }

    public function test_admin_role_management_keeps_one_admin(): void
    {
        $this->loginAs($this->admin)
            ->patchJson("/api/v1/t/ssv-ulm/members/{$this->trainer->id}", ['is_admin' => true])
            ->assertOk()->assertJsonPath('data.is_admin', true);

        $this->patchJson("/api/v1/t/ssv-ulm/members/{$this->admin->id}", ['is_admin' => false])->assertOk();
        $this->loginAs($this->trainer)->patchJson("/api/v1/t/ssv-ulm/members/{$this->trainer->id}", ['is_admin' => false])->assertStatus(422);

        $this->loginAs($this->player)->getJson('/api/v1/t/ssv-ulm/members')->assertForbidden();
    }
}
