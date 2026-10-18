<?php

namespace App\Enums;

enum RsvpStatus: string
{
    case Yes = 'yes';
    case No = 'no';
    case Maybe = 'maybe';
}
