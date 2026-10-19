<?php

return [
    'date_format' => 'ddd, MMM D, h:mm A',
    'categories' => [
        'event_new' => 'New events',
        'event_changed' => 'Changes and cancellations',
        'reminder' => 'Reminders (missing replies, tasks taken)',
        'chat' => 'Chat messages',
        'poll' => 'New polls',
        'notice' => 'New notices',
    ],
    'event_new' => ['title' => 'New event: :title', 'body' => ':when'],
    'series_new' => ['title' => 'New training series: :title', 'body' => 'From :when'],
    'event_changed' => ['title' => 'Event changed: :title', 'body' => 'Now :when'],
    'event_cancelled' => ['title' => 'Cancelled: :title', 'body' => ':when is cancelled.'],
    'event_restored' => ['title' => 'Back on: :title', 'body' => ':when'],
    'series_changed' => ['title' => 'Training series changed: :title', 'body' => 'Next date :when'],
    'series_ended' => ['title' => 'Training series ended: :title', 'body' => 'No more dates from :when.'],
    'reminder' => [
        'title' => 'Please reply: :title',
        'body' => ':when – please accept or decline.',
        'body_deadline' => ':when – please reply by :deadline.',
    ],
    'task_reminder' => ['title' => 'Your task: :tasks', 'body' => ':title · :when'],
    'imported' => ['title' => '{1} :team: 1 new date in the schedule|[2,*] :team: :count new dates in the schedule', 'body' => 'Next: :title, :when'],
    'poll' => ['title' => 'New poll: :title'],
    'notice' => ['title' => 'New notice: :title'],
    'adult' => [
        'guardian_title' => ':name now manages the profile',
        'guardian_body' => ':name has turned 18. Your access as a parent in :tenant has ended.',
        'self_title' => 'Your profile is yours now',
        'self_body' => 'Happy 18th birthday! From now on you manage your profile in :tenant yourself; your parents no longer have access.',
    ],
    'ssl' => [
        'title' => 'SSL check failed',
        'body' => 'The daily check of :host found errors:',
    ],
    'mail' => [
        'footer' => 'Choose which notifications you get in your profile: :url',
    ],
    'push' => [
        'endpoint_invalid' => 'This push service is not supported.',
    ],
];
