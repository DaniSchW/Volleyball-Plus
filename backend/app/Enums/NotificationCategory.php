<?php

namespace App\Enums;

/**
 * What a member can be notified about; each category can be switched on
 * or off per channel in the profile.
 */
enum NotificationCategory: string
{
    case EventNew = 'event_new';
    case EventChanged = 'event_changed';
    case Reminder = 'reminder';
    case Notice = 'notice';
    case Poll = 'poll';

    /** Push for everything; e-mail only for changes and cancellations. */
    public function defaultPush(): bool
    {
        return true;
    }

    public function defaultEmail(): bool
    {
        return $this === self::EventChanged;
    }
}
