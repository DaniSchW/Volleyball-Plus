<?php

namespace Tests;

use App\Enums\PersonRelation;
use App\Enums\TeamRole;
use App\Models\Account;
use App\Models\Person;
use App\Models\PersonAccount;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Support\Auth\SessionManager;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The frontend sends this on every API call (CSRF protection).
        $this->withHeader('X-Requested-With', 'XMLHttpRequest');
    }

    /**
     * On the hosting every request runs in a fresh PHP process; forget the
     * logged-in user between test requests the same way.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    protected function tenant(string $slug = 'ssv-ulm', string $name = 'SSV Ulm Volleyball'): Tenant
    {
        return Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => $name]);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function inTenant(Tenant $tenant, callable $callback): mixed
    {
        return app(TenantContext::class)->run($tenant, $callback);
    }

    /**
     * A member with login and own profile.
     */
    protected function member(Tenant $tenant, string $email, string $first = 'Max', string $last = 'Muster', bool $admin = false, string $gender = 'male'): TenantMember
    {
        return $this->inTenant($tenant, function () use ($email, $first, $last, $admin, $gender) {
            $account = Account::query()->firstOrCreate(['email' => $email]);
            $member = TenantMember::create(['account_id' => $account->id, 'is_admin' => $admin]);
            $person = Person::create(['first_name' => $first, 'last_name' => $last, 'gender' => $gender, 'email' => $email]);
            PersonAccount::create(['person_id' => $person->id, 'tenant_member_id' => $member->id, 'relation' => PersonRelation::Own]);

            return $member;
        });
    }

    protected function personOf(TenantMember $member): Person
    {
        return $this->inTenant(Tenant::find($member->tenant_id), fn () => $member->ownPerson());
    }

    protected function team(Tenant $tenant, string $name = 'Herren 1', string $gender = 'men'): Team
    {
        return $this->inTenant($tenant, fn () => Team::create(['name' => $name, 'gender' => $gender]));
    }

    protected function addToTeam(Team $team, Person $person, TeamRole $role = TeamRole::Player): TeamMembership
    {
        return $this->inTenant(Tenant::find($team->tenant_id), fn () => TeamMembership::create([
            'team_id' => $team->id, 'person_id' => $person->id, 'role' => $role,
        ]));
    }

    /** Continues with the session cookie a response (e.g. a magic-link redemption) has set. */
    protected function withSessionFrom(TestResponse $response): static
    {
        $cookie = collect($response->headers->getCookies())
            ->first(fn ($c) => $c->getName() === config('security.session.cookie'));
        $this->assertNotNull($cookie, 'response did not start a session');

        return $this->withCredentials()->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }

    /** Logs the account in by attaching a real session cookie to the next requests. */
    protected function loginAs(TenantMember|Account $who): static
    {
        $account = $who instanceof Account ? $who : Account::find($who->account_id);
        [, $token] = app(SessionManager::class)->start($account, Request::create('/'));

        return $this->withCredentials()->withUnencryptedCookie(config('security.session.cookie'), $token);
    }
}
