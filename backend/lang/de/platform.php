<?php

return [
    'registered' => 'Danke! Wir prüfen die Anmeldung und melden uns per E-Mail, sobald euer Verein freigeschaltet ist.',
    'slug_taken' => 'Dieses Kürzel ist schon vergeben. Bitte wähle ein anderes.',
    'not_pending' => 'Dieser Verein wartet nicht auf Freigabe.',
    'suspend_first' => 'Nur gesperrte Vereine können gelöscht werden. Sperre den Verein zuerst.',
    'confirm_slug' => 'Bitte gib zur Bestätigung die Adresse (Kürzel) des Vereins genau ein.',
    'mail' => [
        'new' => [
            'title' => 'Neue Vereinsanmeldung: :name',
            'body' => "Ein Verein möchte VolleyOrga nutzen:\n\nVerein: :name\nOrt: :city\nAnsprechpartner: :contact (:email)\nNachricht: :note\n\nFreigeben oder ablehnen in der Plattform-Verwaltung:",
        ],
        'received' => [
            'title' => 'Eure Anmeldung bei VolleyOrga: :name',
            'body' => "Hallo!\n\nDanke für eure Anmeldung von „:name“ bei VolleyOrga. Wir prüfen sie und schicken dir eine E-Mail, sobald euer Verein freigeschaltet ist. Danach meldest du dich als Admin an und legst eure Teams an.",
        ],
        'approved' => [
            'title' => ':name ist freigeschaltet',
            'body' => "Hallo!\n\n„:name“ ist jetzt bei VolleyOrga freigeschaltet. Mit dem Anmeldelink aus der anderen E-Mail meldest du dich als Admin an. Eure Adresse:\n:url\n\nDort legst du Teams an und lädst Spieler, Trainer und Eltern per Link ein.",
        ],
    ],
];
