<?php

return [
    'registered' => 'Thank you! We will review the registration and let you know by e-mail as soon as your club is activated.',
    'slug_taken' => 'This short name is already taken. Please choose another one.',
    'not_pending' => 'This club is not waiting for approval.',
    'mail' => [
        'new' => [
            'title' => 'New club registration: :name',
            'body' => "A club would like to use VolleyOrga:\n\nClub: :name\nCity: :city\nContact: :contact (:email)\nMessage: :note\n\nApprove or reject in the platform administration:",
        ],
        'received' => [
            'title' => 'Your registration with VolleyOrga: :name',
            'body' => "Hello!\n\nThank you for registering “:name” with VolleyOrga. We will review it and send you an e-mail as soon as your club is activated. Then you log in as admin and add your teams.",
        ],
        'approved' => [
            'title' => ':name is activated',
            'body' => "Hello!\n\n“:name” is now activated on VolleyOrga. Use the login link from the other e-mail to log in as admin. Your address:\n:url\n\nThere you add teams and invite players, trainers and parents by link.",
        ],
    ],
];
