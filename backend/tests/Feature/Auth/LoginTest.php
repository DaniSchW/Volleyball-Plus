<?php

namespace Tests\Feature\Auth;

use App\Enums\PersonRelation;
use App\Mail\MagicLinkMail;
use App\Models\Account;
use App\Models\AccountSession;
use App\Models\MinorPermission;
use App\Models\Person;
use App\Models\PersonAccount;
use App\Models\TenantMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function requestToken(string $email = 'max@example.org'): string
    {
        $this->postJson('/api/v1/t/ssv-ulm/auth/login', ['email' => $email])->assertStatus(202);

        $url = null;
        Mail::assertSent(MagicLinkMail::class, function (MagicLinkMail $mail) use (&$url) {
            $url = $mail->url;

            return true;
        });
        $this->assertStringStartsWith('https://localhost/ssv-ulm/login/confirm?token=', $url);

        return substr($url, strpos($url, 'token=') + 6);
    }

    public function test_member_receives_a_link_and_logs_in(): void
    {
        $tenant = $this->tenant();
        $this->member($tenant, 'max@example.org');

        $token = $this->requestToken('MAX@example.org ');

        $response = $this->postJson('/api/v1/auth/consume', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('data.tenant', 'ssv-ulm')
            ->assertCookie(config('security.session.cookie'), null, false);

        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === config('security.session.cookie'));
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertTrue($cookie->isSecure());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertEqualsWithDelta(Carbon::now()->addDays(90)->getTimestamp(), $cookie->getExpiresTime(), 5);

        $this->withCredentials()->withUnencryptedCookie($cookie->getName(), $cookie->getValue())
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'max@example.org')
            ->assertJsonPath('data.tenants.0.slug', 'ssv-ulm');
    }

    public function test_unknown_addresses_get_the_same_answer_but_no_mail(): void
    {
        $tenant = $this->tenant();
        $other = $this->tenant('tsv-anders', 'TSV Anders');
        $this->member($other, 'fremd@example.org');

        $this->postJson('/api/v1/t/ssv-ulm/auth/login', ['email' => 'niemand@example.org'])
            ->assertStatus(202)
            ->assertJsonPath('message', __('auth.link_sent'));
        // Member of another department only.
        $this->postJson('/api/v1/t/ssv-ulm/auth/login', ['email' => 'fremd@example.org'])->assertStatus(202);

        Mail::assertNothingSent();
    }

    public function test_links_work_only_once_and_expire(): void
    {
        $this->member($this->tenant(), 'max@example.org');

        $token = $this->requestToken();
        $this->postJson('/api/v1/auth/consume', ['token' => $token])->assertOk();
        $this->postJson('/api/v1/auth/consume', ['token' => $token])->assertStatus(422);

        Mail::fake();
        $token = $this->requestToken();
        Carbon::setTestNow(Carbon::now()->addMinutes(16));
        $this->postJson('/api/v1/auth/consume', ['token' => $token])->assertStatus(422);
        Carbon::setTestNow();
    }

    public function test_link_requests_are_rate_limited(): void
    {
        $this->tenant();

        foreach (range(1, 5) as $_) {
            $this->postJson('/api/v1/t/ssv-ulm/auth/login', ['email' => 'max@example.org'])->assertStatus(202);
        }
        $this->postJson('/api/v1/t/ssv-ulm/auth/login', ['email' => 'max@example.org'])->assertStatus(429);
    }

    public function test_changing_requests_need_the_ajax_header(): void
    {
        $this->tenant();

        $this->withoutHeader('X-Requested-With')
            ->postJson('/api/v1/t/ssv-ulm/auth/login', ['email' => 'max@example.org'])
            ->assertStatus(419);
    }

    public function test_api_requires_login(): void
    {
        $this->tenant();

        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->getJson('/api/v1/t/ssv-ulm/me')->assertUnauthorized();
    }

    public function test_members_of_other_departments_are_rejected(): void
    {
        $this->tenant();
        $other = $this->member($this->tenant('tsv-anders', 'TSV Anders'), 'fremd@example.org');

        $this->loginAs($other)->getJson('/api/v1/t/ssv-ulm/me')->assertForbidden();
    }

    public function test_logout_ends_the_session(): void
    {
        $member = $this->member($this->tenant(), 'max@example.org');

        $this->loginAs($member)->postJson('/api/v1/auth/logout')->assertOk();

        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertNotNull(AccountSession::first()->revoked_at);
    }

    public function test_session_slides_forward_on_use(): void
    {
        $member = $this->member($this->tenant(), 'max@example.org');
        $this->loginAs($member);

        // Within a day: no database write, no new cookie.
        $this->assertEmpty($this->getJson('/api/v1/me')->assertOk()->headers->getCookies());

        Carbon::setTestNow(Carbon::now()->addDays(80));
        $response = $this->getJson('/api/v1/me')->assertOk();
        $session = AccountSession::first();
        $this->assertEqualsWithDelta(Carbon::now()->addDays(90)->getTimestamp(), $session->expires_at->getTimestamp(), 5);

        // The browser cookie is extended as well.
        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === config('security.session.cookie'));
        $this->assertNotNull($cookie);
        $this->assertEqualsWithDelta(Carbon::now()->addDays(90)->getTimestamp(), $cookie->getExpiresTime(), 5);
        $this->assertTrue($cookie->isHttpOnly());

        Carbon::setTestNow(Carbon::now()->addDays(91));
        $this->getJson('/api/v1/me')->assertUnauthorized();
        Carbon::setTestNow();
    }

    public function test_devices_can_be_listed_and_logged_out(): void
    {
        $member = $this->member($this->tenant(), 'max@example.org');
        $this->loginAs($member);
        $other = AccountSession::first();
        $this->loginAs($member);

        $sessions = $this->getJson('/api/v1/me/sessions')->assertOk()->json('data');
        $this->assertCount(2, $sessions);

        $this->deleteJson('/api/v1/me/sessions/'.$other->id)->assertOk();
        $this->assertNotNull($other->fresh()->revoked_at);
    }

    public function test_language_is_stored_in_the_account(): void
    {
        $member = $this->member($this->tenant(), 'max@example.org');

        $this->loginAs($member)->patchJson('/api/v1/me', ['locale' => 'en'])->assertOk();

        $this->getJson('/api/v1/t/ssv-ulm/teams/unbekannt', ['Accept-Language' => 'de-DE'])
            ->assertNotFound();
        $this->assertSame('en', Account::first()->locale);
        $this->postJson('/api/v1/t/ssv-ulm/teams', [])->assertForbidden();
        $this->assertSame('en', app()->getLocale());
    }

    public function test_child_without_own_login_permission_cannot_log_in(): void
    {
        $tenant = $this->tenant();
        $child = $this->member($tenant, 'kind@example.org', 'Kim', 'Kind');
        $parent = $this->member($tenant, 'eltern@example.org', 'Eva', 'Eltern');
        $this->inTenant($tenant, function () use ($child, $parent) {
            $person = $child->ownPerson();
            PersonAccount::create(['person_id' => $person->id, 'tenant_member_id' => $parent->id, 'relation' => PersonRelation::Guardian]);
            MinorPermission::create(['person_id' => $person->id, 'own_login' => false]);
        });

        $this->loginAs($child)->getJson('/api/v1/t/ssv-ulm/me')->assertForbidden();

        $this->inTenant($tenant, fn () => MinorPermission::query()->update(['own_login' => true]));
        $this->getJson('/api/v1/t/ssv-ulm/me')->assertOk();
    }

    public function test_tenant_me_lists_own_profile_and_children(): void
    {
        $tenant = $this->tenant();
        $parent = $this->member($tenant, 'eltern@example.org', 'Eva', 'Eltern');
        $this->inTenant($tenant, function () use ($parent) {
            $child = Person::create(['first_name' => 'Kim', 'last_name' => 'Eltern', 'gender' => 'female', 'birthday' => '2014-05-01']);
            PersonAccount::create(['person_id' => $child->id, 'tenant_member_id' => $parent->id, 'relation' => PersonRelation::Guardian]);
        });

        $persons = $this->loginAs($parent)->getJson('/api/v1/t/ssv-ulm/me')->assertOk()->json('data.persons');

        $this->assertEqualsCanonicalizing(['self', 'guardian'], array_column($persons, 'relation'));
        $this->assertFalse($this->inTenant($tenant, fn () => TenantMember::first()->is_admin));
    }
}
