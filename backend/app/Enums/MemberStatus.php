<?php

namespace App\Enums;

enum MemberStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
}
