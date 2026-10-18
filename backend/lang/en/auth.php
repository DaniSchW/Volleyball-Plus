<?php

return [
    'link_sent' => 'If the address belongs to a member, a login link is on its way. Please check your inbox.',
    'link_invalid' => 'This link is invalid, expired or has already been used. Just request a new one.',
    'too_many_requests' => 'Too many attempts. Please wait a few minutes.',
    'unauthenticated' => 'Please log in.',
    'not_a_member' => 'You are not a member of this department.',
    'mail' => [
        'login' => [
            'subject' => 'Your login link – :tenant',
            'intro' => 'Hello! Use this link to log in to :tenant:',
        ],
        'join' => [
            'subject' => 'Please confirm your e-mail address – :tenant',
            'intro' => 'Hello! Please confirm your e-mail address with this link to join :tenant:',
        ],
        'validity' => 'The link is valid for :minutes minutes and works only once.',
        'ignore' => 'If you did not request this, you can ignore this e-mail.',
    ],
];
