<?php

namespace Tests\Feature\Persons;

use App\Enums\PersonRelation;
use App\Enums\TeamRole;
use App\Models\MinorPermission;
use App\Models\Person;
use App\Models\PersonAccount;
use App\Models\StoredFile;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\TenantMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Team $team;

    private TenantMember $player;

    private TenantMember $teammate;

    private TenantMember $trainer;

    private TenantMember $admin;

    private Person $playerPerson;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->tenant();
        $this->team = $this->team($this->tenant);
        $this->player = $this->member($this->tenant, 'paul@example.org', 'Paul', 'Spieler');
        $this->teammate = $this->member($this->tenant, 'mia@example.org', 'Mia', 'Mit', gender: 'female');
        $this->trainer = $this->member($this->tenant, 'tom@example.org', 'Tom', 'Trainer');
        $this->admin = $this->member($this->tenant, 'ada@example.org', 'Ada', 'Admin', admin: true, gender: 'female');
        $this->playerPerson = $this->personOf($this->player);
        $this->addToTeam($this->team, $this->playerPerson);
        $this->addToTeam($this->team, $this->personOf($this->teammate));
        $this->addToTeam($this->team, $this->personOf($this->trainer), TeamRole::Trainer);

        $this->inTenant($this->tenant, fn () => $this->playerPerson->forceFill([
            'phone' => '0171 1234567', 'street' => 'Netzweg 1', 'city' => 'Ulm', 'birthday' => '1995-06-15', 'jersey_number' => 7, 'height_cm' => 192,
        ])->save());
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/t/ssv-ulm/persons/{$this->playerPerson->id}{$suffix}";
    }

    public function test_teammates_see_standard_fields_but_details_only_when_shared(): void
    {
        $this->loginAs($this->teammate)->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.jersey_number', 7)
            ->assertJsonPath('data.height_cm', 192)
            ->assertJsonPath('data.phone', null)
            ->assertJsonPath('data.address', null)
            ->assertJsonPath('data.birthday', null)
            ->assertJsonPath('data.editable_fields', []);

        $this->loginAs($this->player)->patchJson($this->url(), ['shared_fields' => ['phone']])->assertOk();

        $this->loginAs($this->teammate)->getJson($this->url())
            ->assertJsonPath('data.phone', '0171 1234567')
            ->assertJsonPath('data.address', null);
    }

    public function test_trainers_and_admins_always_see_details(): void
    {
        $this->loginAs($this->trainer)->getJson($this->url())->assertJsonPath('data.phone', '0171 1234567')->assertJsonPath('data.address.city', 'Ulm');
        $this->loginAs($this->admin)->getJson($this->url())->assertJsonPath('data.birthday', '1995-06-15');

        // Roster uses the same rules.
        $rows = $this->loginAs($this->teammate)->getJson("/api/v1/t/ssv-ulm/teams/{$this->team->id}/members")->json('data');
        $paul = collect($rows)->firstWhere('person.first_name', 'Paul');
        $this->assertNull($paul['person']['phone']);
    }

    public function test_owner_edits_profile_others_cannot(): void
    {
        $this->loginAs($this->player)->patchJson($this->url(), [
            'phone' => '0160 999', 'primary_position' => 'middle', 'secondary_positions' => ['opposite'],
            'reach_height_cm' => 245, 'address' => ['street' => 'Ballgasse 2', 'postal_code' => '89073', 'city' => 'Ulm', 'country' => 'DE'],
        ])->assertOk()
            ->assertJsonPath('data.primary_position', 'middle')
            ->assertJsonPath('data.address.street', 'Ballgasse 2');

        $this->loginAs($this->teammate)->patchJson($this->url(), ['phone' => '0'])->assertForbidden();
        $this->loginAs($this->teammate)->patchJson($this->url(), ['shared_fields' => ['phone', 'address', 'birthday', 'email']])->assertForbidden();
    }

    public function test_trainer_may_set_jersey_number_and_positions_only(): void
    {
        $this->loginAs($this->trainer)->patchJson($this->url(), ['jersey_number' => 12, 'primary_position' => 'setter'])->assertOk();
        $this->loginAs($this->trainer)->patchJson($this->url(), ['phone' => '0'])->assertForbidden();
    }

    public function test_parents_decide_what_the_child_may_edit(): void
    {
        $parent = $this->member($this->tenant, 'eltern@example.org', 'Eva', 'Eltern', gender: 'female');
        $this->inTenant($this->tenant, function () use ($parent) {
            PersonAccount::create(['person_id' => $this->playerPerson->id, 'tenant_member_id' => $parent->id, 'relation' => PersonRelation::Guardian]);
            MinorPermission::create(['person_id' => $this->playerPerson->id, 'own_login' => true]);
        });

        // Child: nothing allowed yet.
        $this->loginAs($this->player)->getJson($this->url())->assertJsonPath('data.editable_fields', [])->assertJsonPath('data.can_manage_profile', false);
        $this->patchJson($this->url(), ['phone' => '1'])->assertForbidden();
        $this->putJson($this->url('/minor-permissions'), ['can_chat' => true])->assertForbidden();

        // Parent allows phone and profile picture.
        $this->loginAs($parent)->getJson($this->url())->assertJsonPath('data.can_manage_minor_permissions', true);
        $this->putJson($this->url('/minor-permissions'), ['can_rsvp' => true, 'editable_profile_fields' => ['phone', 'avatar']])
            ->assertOk()
            ->assertJsonPath('data.can_rsvp', true)
            ->assertJsonPath('data.editable_profile_fields', ['phone', 'avatar']);

        $this->loginAs($this->player)->patchJson($this->url(), ['phone' => '1'])->assertOk();
        $this->patchJson($this->url(), ['last_name' => 'Anders'])->assertForbidden();

        // The trainer of the team cannot change the parents' settings.
        $this->loginAs($this->trainer)->putJson($this->url('/minor-permissions'), ['own_login' => false])->assertForbidden();
    }

    public function test_profile_picture_is_scaled_and_served_to_members(): void
    {
        Storage::fake('local');

        $avatarUrl = $this->loginAs($this->player)
            ->post($this->url('/avatar'), ['image' => UploadedFile::fake()->image('ich.jpg', 2000, 1500)])
            ->assertOk()
            ->json('data.avatar_url');

        $file = $this->inTenant($this->tenant, fn () => StoredFile::first());
        $this->assertSame(512, $file->width);
        $this->assertSame(384, $file->height);
        $this->assertSame('image/webp', $file->mime_type);
        $this->assertStringContainsString('tenants/'.$this->tenant->id.'/avatar/', $file->storage_key);

        $this->loginAs($this->teammate)->get($avatarUrl)->assertOk()->assertHeader('Content-Type', 'image/webp');

        $other = $this->member($this->tenant('tsv-anders', 'TSV Anders'), 'fremd@example.org');
        $this->loginAs($other)->getJson($avatarUrl)->assertForbidden();

        // Replacing deletes the old picture.
        $this->loginAs($this->player)->post($this->url('/avatar'), ['image' => UploadedFile::fake()->image('neu.png', 100, 100)])->assertOk();
        $this->assertSame(1, $this->inTenant($this->tenant, fn () => StoredFile::count()));
        Storage::disk('local')->assertMissing($file->storage_key);
    }

    public function test_non_images_are_rejected(): void
    {
        Storage::fake('local');

        $this->loginAs($this->player)
            ->postJson($this->url('/avatar'), ['image' => UploadedFile::fake()->create('virus.php', 10, 'application/x-php')])
            ->assertStatus(422);
    }

    public function test_children_are_released_on_their_18th_birthday(): void
    {
        $parent = $this->member($this->tenant, 'eltern@example.org', 'Eva', 'Eltern', gender: 'female');
        $this->inTenant($this->tenant, function () use ($parent) {
            $this->playerPerson->forceFill(['birthday' => Carbon::today()->subYears(18)->addDay()])->save();
            PersonAccount::create(['person_id' => $this->playerPerson->id, 'tenant_member_id' => $parent->id, 'relation' => PersonRelation::Guardian]);
            MinorPermission::create(['person_id' => $this->playerPerson->id, 'own_login' => true]);
        });

        $this->artisan('app:release-adults')->assertSuccessful();
        $this->assertTrue($this->inTenant($this->tenant, fn () => $this->playerPerson->hasGuardians()));

        Carbon::setTestNow(Carbon::tomorrow()->addHour());
        $this->artisan('app:release-adults')->assertSuccessful();
        Carbon::setTestNow();

        $this->inTenant($this->tenant, function () {
            $this->assertFalse($this->playerPerson->hasGuardians());
            $this->assertSame(0, MinorPermission::count());
        });
        // Now the former child manages the profile alone.
        $this->loginAs($this->player)->getJson($this->url())->assertJsonPath('data.can_manage_profile', true);
        $this->loginAs($parent)->getJson($this->url())->assertJsonPath('data.can_manage_profile', false);
    }
}
