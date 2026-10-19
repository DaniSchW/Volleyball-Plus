<?php

namespace Tests\Feature\Platform;

use App\Enums\TenantStatus;
use App\Mail\MagicLinkMail;
use App\Mail\NotificationMail;
use App\Models\Account;
use App\Models\Person;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ClubRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private Account $operator;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->operator = Account::create(['email' => 'betreiber@example.org']);
        $this->operator->forceFill(['is_platform_admin' => true])->save();
    }

    private function register(array $data = []): TestResponse
    {
        return $this->withHeader('Accept-Language', 'de')->postJson('/api/v1/clubs/register', $data + [
            'club_name' => 'TSV Blaubeuren Volleyball', 'slug' => 'tsv-blaubeuren', 'city' => 'Blaubeuren',
            'first_name' => 'Bea', 'last_name' => 'Blau', 'gender' => 'female', 'email' => 'Bea@Example.org',
            'note' => 'Wir haben 4 Teams.', 'accept' => true,
        ]);
    }

    private function pending(): Tenant
    {
        return Tenant::query()->where('slug', 'tsv-blaubeuren')->firstOrFail();
    }

    public function test_a_club_registers_and_waits_for_approval(): void
    {
        $this->register()->assertCreated();

        $tenant = $this->pending();
        $this->assertSame(TenantStatus::Pending, $tenant->status);
        $this->assertSame('bea@example.org', $tenant->contact_email);
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $m) => $m->hasTo('betreiber@example.org') && str_contains($m->title, 'TSV Blaubeuren'));
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $m) => $m->hasTo('bea@example.org'));

        // Not reachable until approved, also no login link.
        $this->getJson('/api/v1/t/tsv-blaubeuren')->assertNotFound();
        $this->postJson('/api/v1/auth/login', ['email' => 'bea@example.org'])->assertStatus(202);
        Mail::assertNotSent(MagicLinkMail::class);

        // The first admin has a profile in the club.
        $this->assertSame('Bea', $this->inTenant($tenant, fn () => Person::query()->first()->first_name));
    }

    public function test_validation_and_spam_protection(): void
    {
        $this->register(['slug' => 'platform'])->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->register(['slug' => 'Mit Leerzeichen'])->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->register(['accept' => false])->assertUnprocessable()->assertJsonValidationErrors('accept');
        $this->register(['website' => 'http://spam.example'])->assertUnprocessable();
        $this->tenant('tsv-blaubeuren', 'Schon da');
        $this->register()->assertUnprocessable()->assertJsonPath('errors.slug.0', 'Dieses Kürzel ist schon vergeben. Bitte wähle ein anderes.');
    }

    public function test_platform_admin_approves_suspends_and_rejects(): void
    {
        $this->register()->assertCreated();
        $id = $this->pending()->id;

        $club = $this->tenant('ssv-ulm');
        $member = $this->member($club, 'max@example.org');
        $this->loginAs($member)->getJson('/api/v1/platform/clubs')->assertForbidden();

        $list = $this->loginAs($this->operator)->getJson('/api/v1/platform/clubs')->assertOk()->json('data');
        $this->assertSame(['tsv-blaubeuren', 'ssv-ulm'], array_column($list, 'slug'));
        $this->assertSame(1, $list[0]['members']);

        // Approve: active, admin gets a login link for the club.
        $this->loginAs($this->operator)->postJson("/api/v1/platform/clubs/{$id}/approve")->assertOk()
            ->assertJsonPath('data.1.status', 'active');
        Mail::assertSent(MagicLinkMail::class, fn (MagicLinkMail $m) => $m->hasTo('bea@example.org') && str_contains($m->url, '/tsv-blaubeuren/login/confirm'));
        $this->getJson('/api/v1/t/tsv-blaubeuren')->assertOk();

        // Suspend and reactivate.
        $this->loginAs($this->operator)->postJson("/api/v1/platform/clubs/{$id}/suspend")->assertOk();
        $this->getJson('/api/v1/t/tsv-blaubeuren')->assertNotFound();
        $this->loginAs($this->operator)->postJson("/api/v1/platform/clubs/{$id}/reactivate")->assertOk();
        $this->loginAs($this->operator)->deleteJson("/api/v1/platform/clubs/{$id}")->assertUnprocessable();

        // Rejecting a new registration removes it with its account.
        $this->register(['slug' => 'spam-club', 'email' => 'spam@example.org'])->assertCreated();
        $spam = Tenant::query()->where('slug', 'spam-club')->firstOrFail();
        $this->loginAs($this->operator)->deleteJson("/api/v1/platform/clubs/{$spam->id}")->assertOk();
        $this->assertNull(Tenant::query()->find($spam->id));
        $this->assertNull(Account::query()->where('email', 'spam@example.org')->first());
    }

    public function test_login_from_the_start_page(): void
    {
        $club = $this->tenant('ssv-ulm');
        $this->member($club, 'max@example.org');

        $this->postJson('/api/v1/auth/login', ['email' => 'max@example.org'])->assertStatus(202);
        $url = null;
        Mail::assertSent(MagicLinkMail::class, function (MagicLinkMail $m) use (&$url) {
            $url = $m->url;

            return $m->tenant === null;
        });
        $this->assertStringStartsWith('https://localhost/login/confirm?token=', $url);

        $this->postJson('/api/v1/auth/consume', ['token' => substr($url, strpos($url, 'token=') + 6)])
            ->assertOk()->assertJsonPath('data.tenant', null);

        // Unknown addresses get the same answer, but no mail.
        Mail::fake();
        $this->postJson('/api/v1/auth/login', ['email' => 'niemand@example.org'])->assertStatus(202);
        Mail::assertNothingSent();
        // Platform admins without a club can log in too.
        $this->postJson('/api/v1/auth/login', ['email' => 'betreiber@example.org'])->assertStatus(202);
        Mail::assertSent(MagicLinkMail::class);
        $this->loginAs($this->operator)->getJson('/api/v1/me')->assertJsonPath('data.is_platform_admin', true);
    }

    public function test_platform_admin_deletes_a_suspended_club_completely(): void
    {
        Storage::fake('local');
        $club = $this->tenant('tsv-weg', 'TSV Weg');
        $kept = $this->tenant();
        $ada = $this->member($club, 'ada@example.org', 'Ada', 'Admin', admin: true, gender: 'female');
        $both = $this->member($club, 'max@example.org');
        $this->member($kept, 'max@example.org');
        $team = $this->team($club);
        $this->addToTeam($team, $this->personOf($both));
        $event = $this->loginAs($ada)->postJson('/api/v1/t/tsv-weg/events', [
            'type' => 'training', 'team_ids' => [$team->id], 'starts_at' => '2030-10-20T19:30', 'ends_at' => '2030-10-20T21:30',
        ])->assertCreated()->json('data');
        $this->loginAs($both)->postJson("/api/v1/t/tsv-weg/events/{$event['id']}/responses", ['person_id' => $this->personOf($both)->id, 'status' => 'yes'])->assertOk();
        Storage::disk('local')->put("tenants/{$club->id}/avatar/x.jpg", 'x');

        // Only suspended clubs, and only with the slug typed again.
        $this->loginAs($this->operator)->postJson("/api/v1/platform/clubs/{$club->id}/delete", ['confirm' => 'tsv-weg'])->assertUnprocessable();
        $this->loginAs($this->operator)->postJson("/api/v1/platform/clubs/{$club->id}/suspend")->assertOk();
        $this->loginAs($this->operator)->postJson("/api/v1/platform/clubs/{$club->id}/delete", ['confirm' => 'tsv'])->assertUnprocessable();
        $this->loginAs($ada)->postJson("/api/v1/platform/clubs/{$club->id}/delete", ['confirm' => 'tsv-weg'])->assertForbidden();

        $this->loginAs($this->operator)->postJson("/api/v1/platform/clubs/{$club->id}/delete", ['confirm' => 'tsv-weg'])->assertOk();

        $this->assertNull(Tenant::query()->find($club->id));
        foreach (['events', 'event_responses', 'persons', 'teams', 'tenant_members', 'activity_log'] as $table) {
            $this->assertSame(0, DB::table($table)->where('tenant_id', $club->id)->count(), $table);
        }
        $this->assertFalse(Storage::disk('local')->exists("tenants/{$club->id}/avatar/x.jpg"));
        // Logins only of this club are gone, others stay.
        $this->assertNull(Account::query()->where('email', 'ada@example.org')->first());
        $this->assertNotNull(Account::query()->where('email', 'max@example.org')->first());
        $this->assertNotNull(Tenant::query()->find($kept->id));
    }
}
