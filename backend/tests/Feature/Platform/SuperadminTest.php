<?php

namespace Tests\Feature\Platform;

use App\Enums\MemberStatus;
use App\Mail\MagicLinkMail;
use App\Mail\NotificationMail;
use App\Models\Account;
use App\Models\TenantMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SuperadminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Carbon::setTestNow('2026-10-20 10:00:00');
        config(['platform.superadmins' => ['service@example.org']]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_superadmin_logs_in_without_an_account_and_sees_all_accounts(): void
    {
        $club = $this->tenant();
        $this->member($club, 'ada@example.org', 'Ada', 'Admin', admin: true, gender: 'female');
        $max = $this->member($club, 'max@example.org');

        $this->postJson('/api/v1/auth/login', ['email' => 'Service@Example.org'])->assertStatus(202);
        $url = null;
        Mail::assertSent(MagicLinkMail::class, function (MagicLinkMail $m) use (&$url) {
            $url = $m->url;

            return $m->hasTo('service@example.org');
        });
        $session = $this->postJson('/api/v1/auth/consume', ['token' => substr($url, strpos($url, 'token=') + 6)])->assertOk();
        $this->withSessionFrom($session)->getJson('/api/v1/me')->assertJsonPath('data.is_superadmin', true)->assertJsonPath('data.is_platform_admin', true);
        $this->getJson('/api/v1/platform/clubs')->assertOk();

        $accounts = $this->getJson('/api/v1/platform/accounts')->assertOk()->json('data');
        $this->assertSame(['ada@example.org', 'max@example.org', 'service@example.org'], array_column($accounts, 'email'));
        $this->assertSame([['name' => 'SSV Ulm Volleyball', 'slug' => 'ssv-ulm', 'is_admin' => true, 'active' => true]], $accounts[0]['clubs']);
        $this->assertSame(['max@example.org'], array_column($this->getJson('/api/v1/platform/accounts?search=MAX')->json('data'), 'email'));

        // Deleting needs the address typed again; not the own account, not the last admin of a club.
        $this->postJson("/api/v1/platform/accounts/{$max->account_id}/delete", ['confirm' => 'max@'])->assertUnprocessable();
        $this->postJson("/api/v1/platform/accounts/{$max->account_id}/delete", ['confirm' => 'max@example.org'])->assertOk();
        $this->assertTrue(Account::find($max->account_id)->isDeleted());
        $this->assertSame(MemberStatus::Disabled, $this->inTenant($club, fn () => TenantMember::find($max->id)->status));
        $ada = Account::where('email', 'ada@example.org')->first();
        $this->postJson("/api/v1/platform/accounts/{$ada->id}/delete", ['confirm' => 'ada@example.org'])->assertUnprocessable();
        $own = Account::where('email', 'service@example.org')->first();
        $this->postJson("/api/v1/platform/accounts/{$own->id}/delete", ['confirm' => 'service@example.org'])->assertUnprocessable();
        $this->assertNotContains('max@example.org', array_column($this->getJson('/api/v1/platform/accounts')->json('data'), 'email'));
    }

    public function test_only_superadmins_see_the_accounts(): void
    {
        $operator = Account::create(['email' => 'betreiber@example.org']);
        $operator->forceFill(['is_platform_admin' => true])->save();
        $this->loginAs($operator)->getJson('/api/v1/platform/accounts')->assertForbidden();
        $this->loginAs($operator)->getJson('/api/v1/platform/clubs')->assertOk();
        $player = $this->member($this->tenant(), 'max@example.org');
        $this->loginAs($player)->getJson('/api/v1/platform/accounts')->assertForbidden();
    }

    public function test_unused_accounts_are_warned_and_deleted_after_14_days(): void
    {
        $club = $this->tenant();
        $this->member($club, 'ada@example.org', 'Ada', 'Admin', admin: true, gender: 'female');
        $old = $this->member($club, 'alt@example.org', 'Otto', 'Alt');
        $back = $this->member($club, 'zurueck@example.org', 'Zora', 'Zurück', gender: 'female');
        $recent = $this->member($club, 'aktiv@example.org');
        $lastAdmin = $this->member($this->tenant('tv-alt', 'TV Alt'), 'chef@example.org', 'Chef', 'Alt', admin: true);
        $superadmin = Account::create(['email' => 'service@example.org']);
        $twoYearsAgo = Carbon::now()->subDays(731);
        foreach ([$old, $back, $lastAdmin] as $member) {
            Account::find($member->account_id)->forceFill(['last_active_at' => $twoYearsAgo])->save();
        }
        Account::find($recent->account_id)->forceFill(['last_active_at' => Carbon::now()->subDays(700)])->save();
        $superadmin->forceFill(['last_active_at' => $twoYearsAgo])->save();

        $this->artisan('app:cleanup-inactive-accounts')->assertSuccessful();
        Mail::assertSent(NotificationMail::class, 3);
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $m) => $m->hasTo('alt@example.org')
            && $m->title === 'Dein VolleyOrga-Konto wird gelöscht' && str_contains($m->body, 'am 3. November 2026 (in 14 Tagen)'));
        Mail::assertNotSent(NotificationMail::class, fn (NotificationMail $m) => $m->hasTo('aktiv@example.org') || $m->hasTo('service@example.org'));

        // Zora logs in again within the 14 days and keeps her account.
        Carbon::setTestNow('2026-10-25 10:00:00');
        $this->loginAs($back)->getJson('/api/v1/me')->assertOk();
        $this->assertNull(Account::find($back->account_id)->deletion_warned_at);

        // A second run warns nobody twice; after 14 days the unused account is deleted.
        Mail::fake();
        $this->artisan('app:cleanup-inactive-accounts')->assertSuccessful();
        Mail::assertNothingSent();
        Carbon::setTestNow('2026-11-03 10:01:00');
        $this->artisan('app:cleanup-inactive-accounts')->assertSuccessful();

        $this->assertTrue(Account::find($old->account_id)->isDeleted());
        $this->assertFalse(Account::find($back->account_id)->isDeleted());
        // The only admin of a club stays (shown to the superadmin as overdue).
        $this->assertFalse(Account::find($lastAdmin->account_id)->isDeleted());
        $this->assertSame('chef@example.org', $this->loginAs($superadmin)->getJson('/api/v1/platform/accounts?filter=warned')->json('data.0.email'));
    }
}
