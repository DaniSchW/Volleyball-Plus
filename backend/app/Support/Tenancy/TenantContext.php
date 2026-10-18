<?php

namespace App\Support\Tenancy;

use App\Models\Tenant;

/**
 * Holds the department (tenant) the current request or job operates on.
 *
 * Every tenant-scoped model query goes through this context; without a
 * tenant set, such queries throw instead of silently returning data of
 * all departments.
 */
class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function forget(): void
    {
        $this->tenant = null;
    }

    public function has(): bool
    {
        return $this->tenant !== null;
    }

    public function get(): Tenant
    {
        return $this->tenant ?? throw new MissingTenantContext;
    }

    public function id(): string
    {
        return $this->get()->getKey();
    }

    /**
     * Run a callback in the context of the given tenant and restore the
     * previous context afterwards (used by jobs and console commands).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(Tenant $tenant, callable $callback): mixed
    {
        $previous = $this->tenant;
        $this->tenant = $tenant;

        try {
            return $callback();
        } finally {
            $this->tenant = $previous;
        }
    }
}
