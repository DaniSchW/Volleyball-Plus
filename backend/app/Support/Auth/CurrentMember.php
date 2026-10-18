<?php

namespace App\Support\Auth;

use App\Models\TenantMember;
use App\Support\Tenancy\MissingTenantContext;

/**
 * The logged-in account's membership in the current department; set by the
 * "member" middleware.
 */
class CurrentMember
{
    private ?TenantMember $member = null;

    public function set(TenantMember $member): void
    {
        $this->member = $member;
    }

    public function forget(): void
    {
        $this->member = null;
    }

    public function get(): TenantMember
    {
        return $this->member ?? throw new MissingTenantContext('No member context set.');
    }

    public function has(): bool
    {
        return $this->member !== null;
    }
}
