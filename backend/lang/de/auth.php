<?php

return [
    'link_sent' => 'Falls die Adresse zu einem Mitglied gehört, ist ein Anmeldelink unterwegs. Bitte schau in dein Postfach.',
    'link_invalid' => 'Dieser Link ist ungültig, abgelaufen oder wurde schon benutzt. Fordere einfach einen neuen an.',
    'too_many_requests' => 'Zu viele Versuche. Bitte warte ein paar Minuten.',
    'unauthenticated' => 'Bitte melde dich an.',
    'not_a_member' => 'Du bist kein Mitglied dieser Abteilung.',
    'mail' => [
        'login' => [
            'subject' => 'Dein Anmeldelink – :tenant',
            'intro' => 'Hallo! Mit diesem Link meldest du dich bei :tenant an:',
        ],
        'join' => [
            'subject' => 'Bitte bestätige deine E-Mail-Adresse – :tenant',
            'intro' => 'Hallo! Bitte bestätige mit diesem Link deine E-Mail-Adresse, um :tenant beizutreten:',
        ],
        'validity' => 'Der Link ist :minutes Minuten gültig und funktioniert nur einmal.',
        'ignore' => 'Falls du das nicht angefordert hast, kannst du diese E-Mail ignorieren.',
    ],
];
