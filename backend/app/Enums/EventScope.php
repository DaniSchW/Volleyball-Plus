<?php

namespace App\Enums;

/** team: one or more teams; tenant: set by an admin for all teams. */
enum EventScope: string
{
    case Team = 'team';
    case Tenant = 'tenant';
}
