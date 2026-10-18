<?php

namespace App\Enums;

/** rsvp: accept/decline; info: information only, no reply expected. */
enum ResponseMode: string
{
    case Rsvp = 'rsvp';
    case Info = 'info';
}
