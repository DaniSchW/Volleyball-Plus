<?php

namespace Tests\Feature\Account;

use App\Enums\MemberStatus;
use App\Enums\TeamRole;
use App\Models\Account;
use App\Models\AccountSession;
use App\Models\Person;
use App\Models\TenantMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_person_deletes_the_whole_login_in_all_clubs(): void
    {
        $ulm = $this->tenant();
        $neu = $this->tenant('tsv-neu-ulm', 'TSV Neu-Ulm');
        $this->member($ulm, 'ada@example.org', 'Ada', 'Admin', admin: true, gender: 'female');
        $max = $this->member($ulm, 'max@example.org', 'Max', 'Muster');
        $other = $this->member($neu, 'max@example.org', 'Max', 'Muster');
        $this->addToTeam($this->team($ulm), $this->personOf($max), TeamRole::Player);
        $person = $this->personOf($max);
        $otherPerson = $this->personOf($other);

        // Safeguard: the e-mail address has to be typed again.
        $this->loginAs($max)->withHeader('Accept-Language', 'de')->postJson('/api/v1/me/delete', ['confirm' => 'max@example'])
            ->assertUnprocessable()->assertJsonPath('errors.confirm.0', 'Bitte gib zur Bestätigung deine E-Mail-Adresse genau ein.');

        $this->loginAs($max)->postJson('/api/v1/me/delete', ['confirm' => ' Max@Example.org '])->assertOk();

        $account = Account::query()->findOrFail($max->account_id);
        $this->assertStringEndsWith('@invalid', $account->email);
        $this->assertSame(0, AccountSession::query()->where('account_id', $account->id)->count());
        $this->assertNull(Account::query()->where('email', 'max@example.org')->first());
        foreach ([[$ulm, $max, $person], [$neu, $other, $otherPerson]] as [$tenant, $member, $p]) {
            $this->inTenant($tenant, function () use ($member, $p) {
                $this->assertSame(MemberStatus::Disabled, TenantMember::query()->find($member->id)->status);
                $this->assertNull(Person::query()->find($p->id));
            });
        }
        // Logged out: the old session no longer works.
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_the_last_admin_cannot_delete_the_login(): void
    {
        $ulm = $this->tenant();
        $ada = $this->member($ulm, 'ada@example.org', 'Ada', 'Admin', admin: true, gender: 'female');

        $this->loginAs($ada)->withHeader('Accept-Language', 'de')->postJson('/api/v1/me/delete', ['confirm' => 'ada@example.org'])
            ->assertUnprocessable()->assertJsonPath('errors.confirm.0', 'Du bist der einzige Admin von „SSV Ulm Volleyball“. Ernenne zuerst einen weiteren Admin.');

        $this->member($ulm, 'bob@example.org', 'Bob', 'Admin', admin: true);
        $this->loginAs($ada)->postJson('/api/v1/me/delete', ['confirm' => 'ada@example.org'])->assertOk();
    }

    public function test_the_last_platform_admin_cannot_delete_the_login(): void
    {
        $operator = Account::create(['email' => 'betreiber@example.org']);
        $operator->forceFill(['is_platform_admin' => true])->save();

        $this->loginAs($operator)->postJson('/api/v1/me/delete', ['confirm' => 'betreiber@example.org'])->assertUnprocessable();
        $this->assertSame('betreiber@example.org', $operator->fresh()->email);
    }
}
