<?php

namespace App\Enums;

/**
 * team: shareable link that adds people to a team;
 * person: single-use link that connects an account to an existing profile;
 * department: link into the club without a team (optionally as admin).
 */
enum InvitationKind: string
{
    case Team = 'team';
    case Person = 'person';
    case Department = 'department';
}
