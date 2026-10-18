# Installation auf dem Webhosting (ohne SSH)

Voraussetzungen: PHP 8.5 (mindestens 8.3) mit `pdo_mysql`, `openssl`, `mbstring`, `gd`, `intl`, `curl`; MySQL 8; SSL-Zertifikat für die Domain (z. B. Let's Encrypt im Kundenmenü des Hosters). SSH wird **nicht** benötigt – alles läuft über FTP und den Browser.

## Kurzfassung für ALL-INKL

Alle Einstellungen im **KAS** (Technische Verwaltung):

1. **Domain**: beim Bearbeiten der Domain als Ziel den Ordner `…/volleyball-plus/backend/public` eintragen, PHP-Version 8.5 wählen und das kostenlose Let's-Encrypt-Zertifikat (SSL-Schutz) aktivieren.
2. **Datenbank**: unter *Datenbanken* eine MySQL-Datenbank anlegen; Server ist in der Regel `localhost`.
3. **Hochladen** per FTP (Schritte 1–4 unten), dann den Einrichtungsassistenten im Browser aufrufen.
4. **Cronjob**: unter *Tools → Cronjobs* einen Cronjob anlegen, im Feld *Log / Path* die geheime Adresse von der Abschlussseite des Assistenten eintragen (`https://deine-domain.de/cron/<token>`) und das kürzeste angebotene Intervall wählen. ALL-INKL ruft dabei eine Webadresse auf – genau dafür ist diese Adresse da. Falls das Paket keine Cronjobs enthält, lassen sie sich im Mitgliederbereich als Option dazubuchen.

> Die Menüpunkte im KAS können sich ändern; maßgeblich ist die [Anleitung von ALL-INKL zu Cronjobs](https://all-inkl.com/en/support/tutorials/kas/tools/cronjobs/setup_479.html).

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
4. Adresse, Datenbank, Mailversand (Standard: PHP `mail()`, optional SMTP), die erste Abteilung und **dein Admin-Konto** (Name, E-Mail) eintragen und **Jetzt einrichten** klicken.
5. Der Assistent legt die Tabellen und die Abteilung an und schreibt `backend/.env` (inklusive App-Schlüssel und Cron-Token). Danach ist er nicht mehr erreichbar.
   > Kann PHP die Datei `backend/.env` nicht schreiben, zeigt der Assistent ihren Inhalt an. Dann diesen Text als `.env` speichern, per FTP nach `backend/` hochladen und `backend/storage/app/setup-code.txt` löschen.

6. Über **Zur Anmeldung** auf der Abschlussseite (oder `https://deine-domain.de/<kürzel>/login`) deine E-Mail-Adresse eingeben und den Anmeldelink aus dem Postfach öffnen. Danach legst du Teams an und verteilst Einladungslinks – andere Wege in die App gibt es nicht.

## 5. Cronjob

Die Abschlussseite des Assistenten zeigt beides an:

- **Webadresse** `https://deine-domain.de/cron/<token>` – für Hoster, deren Cronjobs eine URL aufrufen (z. B. ALL-INKL), oder einen externen Dienst wie cron-job.org. Die Adresse geheim halten.
- **oder Befehl** `php …/backend/artisan schedule:run` – für Hoster, deren Cronjobs Befehle ausführen.

Ideal ist ein Aufruf jede Minute. Seltener (z. B. alle 5 Minuten) funktioniert auch – Mails und Erinnerungen gehen dann entsprechend verzögert raus, tägliche Aufgaben laufen trotzdem zuverlässig.

Der Cronjob verschickt Mails und Push-Nachrichten (auch die Erinnerungen vor Antwortfristen), legt Serientermine an, spielt Datenbank-Updates ein und prüft täglich das SSL-Zertifikat.

**Push-Nachrichten** brauchen keine Einrichtung: Die Schlüssel (VAPID) erzeugt die App beim ersten Bedarf selbst und speichert sie verschlüsselt in der Datenbank. Der Server muss nur ausgehende HTTPS-Verbindungen zu den Push-Diensten von Google, Mozilla, Apple und Microsoft aufbauen dürfen (bei ALL-INKL der Fall). Wird der `APP_KEY` geändert, entstehen neue Schlüssel und alle Geräte müssen Push im Profil neu aktivieren.

## 6. Updates

Neues Paket herunterladen und per FTP über die bestehenden Dateien hochladen. `backend/.env` und die Inhalte von `backend/storage/` sind nicht im Paket und bleiben erhalten. Neue Datenbank-Änderungen spielt der Cronjob innerhalb einer Minute automatisch ein.

## Hinweise

- **„Zugriff nur über HTTPS“ trotz https-Adresse:** Der Hoster beendet SSL dann in einem Proxy vor PHP. Proxies in privaten Netzen werden automatisch erkannt. Nutzt der Proxy öffentliche Adressen, in `backend/.env` `TRUSTED_PROXIES=*` ergänzen.
- `FORCE_HTTPS` in `.env` nie auf `false` setzen.
