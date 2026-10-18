# Installation auf dem Webhosting (ohne SSH)

Voraussetzungen: PHP 8.5 (mindestens 8.3) mit `pdo_mysql`, `openssl`, `mbstring`, `gd`, `intl`, `curl`, `bcmath`; MySQL 8; SSL-Zertifikat für die Domain (z. B. Let's Encrypt im Kundenmenü des Hosters). SSH wird **nicht** benötigt – alles läuft über FTP und den Browser.

## 1. Paket holen

Jeder Push auf `main` baut in GitHub Actions ein fertiges Paket: Repository → *Actions* → letzter Lauf von **CI** → Artefakt **volleyball-plus** herunterladen und entpacken.

Das Paket enthält `backend/` inklusive aller PHP-Abhängigkeiten und der fertig gebauten App. Auf dem Server muss nichts gebaut werden.

## 2. Datenbank anlegen

Im Kundenmenü des Hosters eine MySQL-Datenbank mit Benutzer anlegen (Zeichensatz `utf8mb4`, Sortierung `utf8mb4_unicode_ci`). Server, Datenbankname, Benutzer und Passwort notieren.

## 3. Hochladen per FTP

1. Inhalt des Pakets in einen Ordner auf dem Webspace laden, z. B. `volleyball-plus/`.
2. **Startverzeichnis der Domain** (Document Root) im Kundenmenü auf `volleyball-plus/backend/public` setzen.
   > Geht das beim Hoster nicht: Domain auf `volleyball-plus/` zeigen lassen und die mitgelieferte Datei `htaccess-root.txt` dort als `.htaccess` speichern. Sie leitet alle Aufrufe nach `backend/public` weiter, sodass `.env`, `vendor/` und `storage/` nie erreichbar sind.
3. Für die Ordner `backend/storage` (mit allen Unterordnern) und `backend/bootstrap/cache` im FTP-Programm Schreibrechte für PHP setzen (meist `755`, falls das nicht reicht `775`).

## 4. Einrichtung im Browser

1. `https://deine-domain.de` aufrufen – die App leitet automatisch zum Einrichtungsassistenten (`/install`).
2. Der Assistent prüft die Voraussetzungen (HTTPS, PHP-Version, Erweiterungen, Schreibrechte). Rot markierte Punkte zuerst beheben.
3. **Einrichtungscode:** Der Assistent legt die Datei `backend/storage/app/setup-code.txt` an. Diese per FTP öffnen und den Code eintragen. So kann niemand Fremdes eine frisch hochgeladene Installation übernehmen.
4. Adresse, Datenbank, Mailversand (Standard: PHP `mail()`, optional SMTP) und die erste Abteilung eintragen und **Jetzt einrichten** klicken.
5. Der Assistent legt die Tabellen und die Abteilung an und schreibt `backend/.env` (inklusive App-Schlüssel und Cron-Token). Danach ist er nicht mehr erreichbar.
   > Kann PHP die Datei `backend/.env` nicht schreiben, zeigt der Assistent ihren Inhalt an. Dann diesen Text als `.env` speichern, per FTP nach `backend/` hochladen und `backend/storage/app/setup-code.txt` löschen.

## 5. Cronjob

Die Abschlussseite des Assistenten zeigt beides an:

- **Cronjob im Kundenmenü** (jede Minute): `php …/backend/artisan schedule:run`
- **oder**, falls der Hoster nur Webadressen aufrufen kann bzw. gar keine Cronjobs anbietet: die geheime Adresse `https://deine-domain.de/cron/<token>` minütlich aufrufen lassen, z. B. über cron-job.org.

Der Cronjob verschickt Mails und Push-Nachrichten, legt Serientermine an, spielt Datenbank-Updates ein und prüft täglich das SSL-Zertifikat.

## 6. Updates

Neues Paket herunterladen und per FTP über die bestehenden Dateien hochladen. `backend/.env` und die Inhalte von `backend/storage/` sind nicht im Paket und bleiben erhalten. Neue Datenbank-Änderungen spielt der Cronjob innerhalb einer Minute automatisch ein.

## Hinweise

- **„Zugriff nur über HTTPS“ trotz https-Adresse:** Der Hoster beendet SSL dann in einem Proxy vor PHP. Proxies in privaten Netzen werden automatisch erkannt. Nutzt der Proxy öffentliche Adressen, in `backend/.env` `TRUSTED_PROXIES=*` ergänzen.
- `FORCE_HTTPS` in `.env` nie auf `false` setzen.
