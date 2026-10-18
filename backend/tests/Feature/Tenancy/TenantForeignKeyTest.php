<?php

namespace Tests\Feature\Tenancy;

use App\Models\Team;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Checks the composite foreign keys built by the tenantKey()/tenantForeign()
 * macros. Uses DatabaseMigrations instead of RefreshDatabase because the
 * probe table is DDL, which MySQL cannot run inside a test transaction.
 */
class TenantForeignKeyTest extends TestCase
{
    use DatabaseMigrations;

    private Team $teamOfA;

    private Team $teamOfB;

    private Tenant $a;

    protected function setUp(): void
    {
        parent::setUp();

        // A child table built the same way every department table is.
        Schema::create('isolation_probe', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->tenantForeign('team_id', 'teams');
        });

        $context = app(TenantContext::class);
        $this->a = Tenant::create(['slug' => 'abteilung-a', 'name' => 'Abteilung A']);
        $b = Tenant::create(['slug' => 'abteilung-b', 'name' => 'Abteilung B']);
        $this->teamOfA = $context->run($this->a, fn () => Team::create(['name' => 'Herren 1', 'gender' => 'men']));
        $this->teamOfB = $context->run($b, fn () => Team::create(['name' => 'Damen 1', 'gender' => 'women']));
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('isolation_probe');

        parent::tearDown();
    }

    public function test_database_rejects_references_across_tenants(): void
    {
        $this->expectException(QueryException::class);

        DB::table('isolation_probe')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->a->id,
            'team_id' => $this->teamOfB->id,
        ]);
    }

    public function test_database_accepts_references_within_a_tenant(): void
    {
        DB::table('isolation_probe')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->a->id,
            'team_id' => $this->teamOfA->id,
        ]);

        $this->assertSame(1, DB::table('isolation_probe')->count());
    }
}
