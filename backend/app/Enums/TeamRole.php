<?php

namespace App\Enums;

enum TeamRole: string
{
    case Player = 'player';
    case Trainer = 'trainer';
    case Organizer = 'organizer';
}
