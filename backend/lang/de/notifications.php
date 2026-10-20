<?php

return [
    'date_format' => 'dd D.M. HH:mm [Uhr]',
    'categories' => [
        'event_new' => 'Neue Termine',
        'event_changed' => 'Änderungen und Absagen',
        'reminder' => 'Erinnerungen (offene Antworten, übernommene Aufgaben)',
        'chat' => 'Chat-Nachrichten',
        'poll' => 'Neue Umfragen',
        'notice' => 'Neue Infos',
    ],
    'event_new' => ['title' => 'Neuer Termin: :title', 'body' => ':when'],
    'series_new' => ['title' => 'Neue Trainingsreihe: :title', 'body' => 'Ab :when'],
    'event_changed' => ['title' => 'Termin geändert: :title', 'body' => 'Jetzt :when'],
    'event_cancelled' => ['title' => 'Abgesagt: :title', 'body' => ':when fällt aus.'],
    'event_restored' => ['title' => 'Findet doch statt: :title', 'body' => ':when'],
    'series_changed' => ['title' => 'Trainingsreihe geändert: :title', 'body' => 'Nächster Termin :when'],
    'series_ended' => ['title' => 'Trainingsreihe beendet: :title', 'body' => 'Ab :when entfallen die Termine.'],
    'reminder' => [
        'title' => 'Bitte antworten: :title',
        'body' => ':when – bitte sag zu oder ab.',
        'body_deadline' => ':when – Antwort bitte bis :deadline.',
    ],
    'task_reminder' => ['title' => 'Deine Aufgabe: :tasks', 'body' => ':title · :when'],
    'imported' => ['title' => '{1} :team: 1 neuer Termin im Spielplan|[2,*] :team: :count neue Termine im Spielplan', 'body' => 'Als Nächstes: :title, :when'],
    'poll' => ['title' => 'Neue Umfrage: :title', 'reminder' => 'Erinnerung: Umfrage „:title“', 'until' => 'Abstimmen bis :date'],
    'notice' => ['title' => 'Neue Info: :title'],
    'adult' => [
        'guardian_title' => ':name verwaltet das Profil jetzt selbst',
        'guardian_body' => ':name ist 18 geworden. Dein Zugriff als Elternteil in :tenant ist damit beendet.',
        'self_title' => 'Dein Profil gehört jetzt dir',
        'self_body' => 'Alles Gute zum 18. Geburtstag! Ab jetzt verwaltest du dein Profil in :tenant selbst, deine Eltern haben keinen Zugriff mehr.',
    ],
    'ssl' => [
        'title' => 'SSL-Prüfung fehlgeschlagen',
        'body' => 'Die tägliche Prüfung von :host hat Fehler gefunden:',
    ],
    'mail' => [
        'footer' => 'Welche Benachrichtigungen du bekommst, stellst du in deinem Profil ein: :url',
    ],
    'push' => [
        'endpoint_invalid' => 'Dieser Push-Dienst wird nicht unterstützt.',
    ],
];
