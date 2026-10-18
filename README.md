# Volleyball Plus

Team-Organisations-App (PWA) für Volleyball-Abteilungen – eigene Alternative zu SpielerPlus und Spond.
Mandantenfähig, zweisprachig (DE/EN), Login per Magic Link.

Technik: PHP 8.5 (Laravel 13, API) · MySQL 8 · React-PWA. Läuft auf klassischem Webhosting.

## Dokumentation

- [Spezifikation](docs/spezifikation.md) – fachliche Anforderungen
- [Technischer Umsetzungsplan](docs/umsetzungsplan.md) – Architektur, Stack, Phasen, Entscheidungen
- [Datenmodell](docs/datenmodell.md) – Tabellen, Mandantentrennung, Indizes
- [Installation auf dem Webhosting](docs/deployment.md)

## Aufbau

```
backend/    Laravel-API, Migrationen, Tests – backend/public ist der Document Root
frontend/   React-PWA (Vite), baut direkt nach backend/public
deploy/     Paket-Skript und .htaccess für Hosting ohne wählbaren Document Root
docs/       Spezifikation, Plan, Datenmodell, Installation
```

## Lokale Entwicklung

```sh
# Backend (http://127.0.0.1:8000)
cd backend
composer install
cp .env.example .env          # lokal: DB_CONNECTION=sqlite, FORCE_HTTPS=false
php artisan key:generate      # ohne APP_KEY startet stattdessen der Einrichtungsassistent
php artisan migrate
php artisan serve

# Frontend mit Hot Reload (http://localhost:5173, leitet /api an Laravel weiter)
cd frontend
npm install
npm run dev
```

Tests: `cd backend && php artisan test` und `cd frontend && npm test`.

## Status

Phase 0 (Fundament) umgesetzt: Mandantentrennung, HTTPS-Erzwingung und SSL-Selbstprüfung, Mailversand per PHP `mail()`/SMTP, Cron/Queue, i18n DE/EN, App-Gerüst mit Menü oben rechts, CI und Upload-Paket, Einrichtungsassistent im Browser (kein SSH nötig).
Phase 1 (Login & Struktur) umgesetzt: Login per Magic Link mit 90-Tage-Sitzung, Teams mit Einstellungen, Rollen (Admin, Trainer, Betreuer, Spieler, Eltern), Einladungslinks (auch Kinder-Anmeldung durch Eltern), Profile mit Freigaben und Profilbild, Kinderrechte und Ablösung mit 18.
Phase 2 (Termine & Zu-/Absagen) umgesetzt: Trainings als Serie, Spieltage mit Gegner und Logos, Zusatztermine (auch als reine Info), abteilungsweite Termine, Zu-/Absage/Vielleicht mit Frist, Pflicht-Grund und Teilnehmermaximum, Startseite mit Infobereich und Infinite Scroll, Wegbeschreibung, Kalender-Abo.
Phase 3 (Benachrichtigungen) umgesetzt: Web-Push (Android, Desktop, iOS ab 16.4 als Home-Bildschirm-App) mit Installationshinweis für iPhone, E-Mail-Benachrichtigungen, Einstellungen pro Kategorie, Erinnerung 24 h vor der Antwortfrist, Meldungen zu neuen und geänderten Terminen, Absagen und Infos, Mails zur Ablösung mit 18 und bei SSL-Problemen.
Phase 4 (Training) umgesetzt: admin-pflegbarer Ligabaum mit Vorlage, Team-Hierarchie aus der Liga, Quernominierung von unten nach oben mit Geschlechtsregel und Freigabezeitpunkt (Team-Standard, im Termin änderbar), Trainingsgruppen mit eigenen Trainings, Schiedsrichter-Lizenz mit Ablaufhinweis, Zusagen nach Position für Trainer.
Phase 6 (Statistik) umgesetzt: Zusagequoten je Team mit frei wählbarem Zeitraum (Schnellwahl 4 Wochen/Saison), Filter nach Terminart, Balkendiagramm für Trainer und Admins, Einzeltermine mit Antwortzeitpunkt; Spieler sehen nur die eigene Quote, Betreuer keine.
Phase 5 (Spielplan-Import) umgesetzt: Spieltage je Mannschaft aus .ics/.ical oder SAMS-CSV mit Vorschau, Heim/Auswärts, Halle, Schiedsgericht-Terminen und Abgleich beim erneuten Import.
Phase 7 (Aufgaben, Helferdienste, Umfragen) umgesetzt: Aufgaben mit Plätzen zu jedem Termin, Terminart Helferdienst mit Helfer-Statistik, Seite „Meine Aufgaben“, Umfragen pro Team und abteilungsweit (Mehrfachauswahl, anonym, Textantwort, Frist) mit Push und Hinweis auf der Startseite.
Phase 8 (Chat) umgesetzt: Teamchats automatisch, Admin-Chats mit frei gewählten Mitgliedern, Gruppenchat oder Themen, antworten/bearbeiten/löschen, Live-Aktualisierung per Polling (4 s), Ungelesen-Zähler, Push an alle, die den Chat nicht gerade offen haben; Fotos, Videos, PDF sowie Office- und LibreOffice-Dateien als Anhänge.
Nächster Schritt: Phase 9 – Kasse & Hallen.
