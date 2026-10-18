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
Nächster Schritt: Phase 1 – Login per Magic Link, Teams, Personen, Rollen, Einladungslinks.
