# Volleyball Plus – Hinweise für die Entwicklung

Fachliche Grundlage: `docs/spezifikation.md`, Entscheidungen in `docs/umsetzungsplan.md` (Abschnitt 9), Tabellen in `docs/datenmodell.md`. Bei neuen Entscheidungen die Dokumente mitpflegen.

## Rahmen

- Läuft auf klassischem Webhosting: PHP 8.5 + MySQL 8. Keine dauerhaft laufenden Prozesse, keine WebSockets, kein Redis. Hintergrundarbeit über Laravel-Queue (`database`) + Scheduler per Cron.
- Kein SSH beim Hosting: Alles, was beim Betrieb nötig ist, muss über Browser (Einrichtungsassistent `/install`, später Admin-Bereich) oder Cron laufen. Neue Migrationen spielt `app:migrate-pending` per Cron automatisch ein.
- Hoster ALL-INKL: Der Cron ruft `/cron/{token}` per HTTP auf (Zeitlimit eines Webaufrufs, evtl. seltener als minütlich). Regeln dazu stehen oben in `backend/routes/console.php` und werden von `SslCheckScheduleTest` geprüft.
- Lokal ist PHP 8.3 installiert – Code muss auf 8.3 und 8.5 laufen (Laravel 13 verlangt ≥ 8.3).
- Kommunikation mit dem Nutzer und Doku auf Deutsch; Code, Kommentare und Commit-Betreff-Präfixe englisch/deutsch wie im Bestand.

## Mandantentrennung (Pflicht)

- Jede fachliche Tabelle: `$table->tenantKey();` und Verweise innerhalb der Abteilung mit `$table->tenantForeign('x_id', 'xs');` (zusammengesetzter FK).
- Jedes fachliche Model: `use BelongsToTenant, HasUlids;`. `tenant_id` nie in `$fillable`.
- Abteilungsrouten unter `Route::prefix('t/{tenant}')->middleware('tenant')`. Jobs/Commands nutzen `TenantContext::run()`.
- Für jedes neue Model einen Isolationstest ergänzen (siehe `tests/Feature/Tenancy`).

## Konventionen

- Alle UI-Texte über i18n: Frontend `frontend/src/locales/<lang>/*.json` (Test prüft gleiche Schlüssel in allen Sprachen), Backend `backend/lang/<lang>/`.
- Rechte immer serverseitig (Policies); Frontend blendet nur aus.
- Tests, die DDL ausführen, nutzen `DatabaseMigrations` statt `RefreshDatabase` (MySQL beendet sonst die Test-Transaktion).

## Prüfen vor dem Commit

```sh
cd backend && vendor/bin/pint --test && php artisan test
cd frontend && npm run typecheck && npm test && npm run build
```

CI (`.github/workflows/ci.yml`) testet das Backend gegen MySQL 8 und PHP 8.5 und baut auf `main` das Upload-Paket.
