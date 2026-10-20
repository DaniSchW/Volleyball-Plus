<?php

namespace Tests\Feature\Platform;

use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegalTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_enters_the_operator_details_for_the_legal_pages(): void
    {
        config(['platform.superadmins' => ['service@example.org']]);
        $this->getJson('/api/v1/legal')->assertOk()
            ->assertJsonPath('data.complete', false)->assertJsonPath('data.email', 'service@example.org')->assertJsonPath('data.country', 'Deutschland');

        $details = ['name' => 'Erika Muster', 'street' => 'Musterweg 1', 'postal_code' => '89073', 'city' => 'Ulm', 'email' => 'service@example.org', 'phone' => '0731 1234'];
        $operator = Account::create(['email' => 'betreiber@example.org']);
        $operator->forceFill(['is_platform_admin' => true])->save();
        $this->loginAs($operator)->putJson('/api/v1/platform/legal', $details)->assertForbidden();
        $superadmin = Account::create(['email' => 'service@example.org']);
        $this->loginAs($superadmin)->putJson('/api/v1/platform/legal', ['name' => 'Erika Muster'])->assertUnprocessable();
        $this->loginAs($superadmin)->putJson('/api/v1/platform/legal', $details + ['vat_id' => ' '])->assertOk()
            ->assertJsonPath('data.complete', true)->assertJsonPath('data.vat_id', null);

        $this->getJson('/api/v1/legal')->assertJsonPath('data.street', 'Musterweg 1')->assertJsonPath('data.updated_on', now()->toDateString());
    }

    public function test_promotional_emails_only_with_consent(): void
    {
        config(['platform.superadmins' => ['service@example.org']]);
        $max = $this->member($this->tenant(), 'max@example.org');
        $this->loginAs($max)->getJson('/api/v1/me')->assertJsonPath('data.marketing_opt_in', false);
        $this->loginAs($max)->patchJson('/api/v1/me', ['marketing_opt_in' => true])->assertOk()->assertJsonPath('data.marketing_opt_in', true);

        $superadmin = Account::create(['email' => 'service@example.org']);
        $this->assertSame(['max@example.org'], array_column($this->loginAs($superadmin)->getJson('/api/v1/platform/accounts?filter=marketing')->json('data'), 'email'));

        // Revocable any time.
        $this->loginAs($max)->patchJson('/api/v1/me', ['marketing_opt_in' => false])->assertOk()->assertJsonPath('data.marketing_opt_in', false);
        $this->assertSame([], $this->loginAs($superadmin)->getJson('/api/v1/platform/accounts?filter=marketing')->json('data'));
    }
}
