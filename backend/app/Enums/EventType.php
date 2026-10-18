<?php

namespace App\Enums;

/** Training (no icon), match day (trophy), extra date (e.g. meeting), helper service. */
enum EventType: string
{
    case Training = 'training';
    case Match = 'match';
    case Extra = 'extra';
    case Helper = 'helper';
}
