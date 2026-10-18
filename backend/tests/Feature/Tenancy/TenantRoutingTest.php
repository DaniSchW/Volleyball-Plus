<?php

namespace Tests\Feature\Tenancy;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_department_is_resolved_from_the_url(): void
    {
        Tenant::create(['slug' => 'ssv-ulm', 'name' => 'SSV Ulm Volleyball']);

        $this->getJson('/api/v1/t/ssv-ulm')
            ->assertOk()
            ->assertJsonPath('data.name', 'SSV Ulm Volleyball')
            ->assertJsonPath('data.default_locale', 'de');
    }

    public function test_unknown_department_returns_404(): void
    {
        $this->getJson('/api/v1/t/gibt-es-nicht')->assertNotFound();
    }

    public function test_suspended_department_returns_404(): void
    {
        Tenant::create(['slug' => 'gesperrt', 'name' => 'Gesperrt', 'status' => TenantStatus::Suspended]);

        $this->getJson('/api/v1/t/gesperrt')->assertNotFound();
    }

    public function test_tenant_context_is_cleared_after_the_request(): void
    {
        Tenant::create(['slug' => 'ssv-ulm', 'name' => 'SSV Ulm Volleyball']);

        $this->getJson('/api/v1/t/ssv-ulm')->assertOk();

        $this->assertFalse(app(TenantContext::class)->has());
    }
}
