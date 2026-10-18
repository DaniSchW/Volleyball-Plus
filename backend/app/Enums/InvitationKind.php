<?php

namespace App\Enums;

/**
 * team: shareable link that adds people to a team;
 * person: single-use link that connects an account to an existing profile.
 */
enum InvitationKind: string
{
    case Team = 'team';
    case Person = 'person';
}
