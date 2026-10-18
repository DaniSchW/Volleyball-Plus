<?php

namespace Tests\Feature\Tenancy;

use App\Models\Team;
use App\Models\Tenant;
use App\Support\Tenancy\MissingTenantContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $a;

    private Tenant $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Tenant::create(['slug' => 'abteilung-a', 'name' => 'Abteilung A']);
        $this->b = Tenant::create(['slug' => 'abteilung-b', 'name' => 'Abteilung B']);
    }

    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }

    public function test_queries_only_return_rows_of_the_active_tenant(): void
    {
        $this->context()->run($this->a, fn () => Team::create(['name' => 'Herren 1', 'gender' => 'men']));
        $this->context()->run($this->b, fn () => Team::create(['name' => 'Damen 1', 'gender' => 'women']));

        $namesA = $this->context()->run($this->a, fn () => Team::pluck('name')->all());
        $namesB = $this->context()->run($this->b, fn () => Team::pluck('name')->all());

        $this->assertSame(['Herren 1'], $namesA);
        $this->assertSame(['Damen 1'], $namesB);
    }

    public function test_rows_of_another_tenant_cannot_be_found_by_id(): void
    {
        $foreign = $this->context()->run($this->b, fn () => Team::create(['name' => 'Damen 1', 'gender' => 'women']));

        $found = $this->context()->run($this->a, fn () => Team::find($foreign->id));

        $this->assertNull($found);
    }

    public function test_queries_without_tenant_context_fail(): void
    {
        $this->expectException(MissingTenantContext::class);

        Team::query()->get();
    }

    public function test_creating_without_tenant_context_fails(): void
    {
        $this->expectException(MissingTenantContext::class);

        Team::create(['name' => 'Herren 1', 'gender' => 'men']);
    }

    public function test_new_rows_get_the_active_tenant(): void
    {
        $team = $this->context()->run($this->a, fn () => Team::create(['name' => 'Herren 1', 'gender' => 'men']));

        $this->assertSame($this->a->id, $team->tenant_id);
    }

    public function test_a_row_cannot_be_created_for_another_tenant(): void
    {
        $this->expectException(MissingTenantContext::class);

        $this->context()->run($this->a, fn () => (new Team(['name' => 'Herren 1', 'gender' => 'men']))
            ->forceFill(['tenant_id' => $this->b->id])
            ->save());
    }

    public function test_tenant_id_cannot_be_mass_assigned(): void
    {
        $team = $this->context()->run($this->a, fn () => Team::create([
            'name' => 'Herren 1',
            'gender' => 'men',
            'tenant_id' => $this->b->id,
        ]));

        $this->assertSame($this->a->id, $team->tenant_id);
    }

    public function test_the_tenant_of_a_row_cannot_be_changed(): void
    {
        $team = $this->context()->run($this->a, fn () => Team::create(['name' => 'Herren 1', 'gender' => 'men']));

        $this->expectException(MissingTenantContext::class);

        $this->context()->run($this->a, function () use ($team) {
            $team->forceFill(['tenant_id' => $this->b->id])->save();
        });
    }

    public function test_context_is_restored_after_run(): void
    {
        $this->context()->set($this->a);

        $this->context()->run($this->b, fn () => null);

        $this->assertSame($this->a->id, $this->context()->id());
    }
}
