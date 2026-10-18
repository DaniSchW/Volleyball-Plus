# Installation auf dem Webhosting

Voraussetzungen: PHP 8.5 (mit `pdo_mysql`, `openssl`, `mbstring`, `gd`, `intl`, `bcmath`), MySQL 8, SSL-Zertifikat für die Domain (z. B. Let's Encrypt im Kundenmenü des Hosters).

## 1. Paket holen

Jeder Push auf `main` baut in GitHub Actions ein fertiges Paket: Repository → *Actions* → letzter Lauf von **CI** → Artefakt **volleyball-plus** herunterladen. Alternativ lokal: `./deploy/build-release.sh` → `release/volleyball-plus.zip`.

Das Paket enthält `backend/` inklusive aller PHP-Abhängigkeiten und der fertig gebauten App. Auf dem Server muss nichts gebaut werden.

## 2. Hochladen

Inhalt des Pakets per SFTP/FTP in einen Ordner auf dem Webspace laden, z. B. `volleyball-plus/`.

**Startverzeichnis der Domain** (Document Root) im Kundenmenü auf `volleyball-plus/backend/public` setzen.

> Geht das beim Hoster nicht: Domain auf `volleyball-plus/` zeigen lassen und die mitgelieferte Datei `htaccess-root.txt` dort als `.htaccess` speichern. Sie leitet alle Aufrufe nach `backend/public` weiter, sodass `.env`, `vendor/` und `storage/` nie erreichbar sind.

## 3. Datenbank und Konfiguration

1. Im Kundenmenü eine MySQL-Datenbank anlegen (Zeichensatz `utf8mb4`, Sortierung `utf8mb4_unicode_ci`).
2. `backend/.env.example` als `backend/.env` kopieren und ausfüllen:
   - `APP_URL=https://deine-domain.de`
   - `APP_ENV=production`, `APP_DEBUG=false`
   - `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
   - `MAIL_MAILER=phpmail` (Standard, PHP `mail()`) oder `smtp` mit `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_SCHEME`
   - `MAIL_FROM_ADDRESS` – eine Adresse deiner Domain, sonst landen Mails im Spam
   - `FORCE_HTTPS=true` (nie abschalten)
   - Terminiert der Hoster SSL in einem Proxy vor PHP (die App meldet dann „nur über HTTPS“ trotz https-Adresse): `TRUSTED_PROXIES=*`
3. Mit SSH im Ordner `backend/`:
   ```sh
   php artisan key:generate
   php artisan migrate --force
   php artisan config:cache && php artisan route:cache
   php artisan security:check-ssl
   ```
   Ohne SSH: Diese Schritte übernimmt später der Einrichtungsassistent im Browser (folgt mit Phase 1).
4. Ordner `backend/storage` und `backend/bootstrap/cache` müssen für PHP beschreibbar sein.

## 4. Cronjob

Im Kundenmenü einen Cronjob **jede Minute** anlegen:

```
php /pfad/zu/volleyball-plus/backend/artisan schedule:run
```

Er verschickt Mails und Push-Nachrichten, legt Serientermine an und prüft täglich das SSL-Zertifikat.

> Kein Cron verfügbar: In `.env` `CRON_TOKEN` auf einen langen Zufallswert setzen und bei einem externen Cron-Dienst (z. B. cron-job.org) die URL `https://deine-domain.de/cron/<CRON_TOKEN>` minütlich aufrufen lassen.

## 5. Updates

Neues Paket hochladen (die `.env` und `backend/storage/` bleiben erhalten), danach `php artisan migrate --force` und `php artisan config:cache && php artisan route:cache`.
