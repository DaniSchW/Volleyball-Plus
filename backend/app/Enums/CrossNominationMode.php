<?php

namespace App\Enums;

/**
 * When cross-nominated guests may start to accept a training (team default,
 * overridable per event).
 */
enum CrossNominationMode: string
{
    case Immediately = 'immediately';
    case HoursBeforeStart = 'hours_before_start';
    case AfterRsvpDeadline = 'after_rsvp_deadline';
}
