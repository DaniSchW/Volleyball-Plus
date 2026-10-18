<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Schema\Blueprint;

/**
 * Blueprint macros so every department table is built the same way:
 *
 *   $table->tenantKey();                       // tenant_id + unique (tenant_id, id)
 *   $table->tenantForeign('team_id', 'teams'); // (tenant_id, team_id) -> teams(tenant_id, id)
 *
 * The composite foreign keys make it impossible at database level for a
 * row to reference data of another department.
 */
class TenantSchema
{
    public static function register(): void
    {
        Blueprint::macro('tenantKey', function (): void {
            /** @var Blueprint $this */
            $this->char('tenant_id', 26);
            $this->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $this->unique(['tenant_id', 'id']);
        });

        Blueprint::macro('tenantForeign', function (string $column, string $on, bool $nullable = false, string $onDelete = 'cascade'): void {
            /** @var Blueprint $this */
            $definition = $this->char($column, 26);
            if ($nullable) {
                $definition->nullable();
            }

            $this->foreign(['tenant_id', $column])
                ->references(['tenant_id', 'id'])
                ->on($on)
                ->onDelete($onDelete);
        });
    }
}
