<?php

namespace App\Support\Tenancy;

use LogicException;

class MissingTenantContext extends LogicException
{
    public function __construct(string $message = 'No tenant context set for a tenant-scoped operation.')
    {
        parent::__construct($message);
    }
}
