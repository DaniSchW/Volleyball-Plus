# Installation auf dem Webhosting (ohne SSH)

Voraussetzungen: PHP 8.5 (mindestens 8.3) mit `pdo_mysql`, `openssl`, `mbstring`, `gd`, `intl`, `curl`; MySQL 8; SSL-Zertifikat für die Domain (z. B. Let's Encrypt im Kundenmenü des Hosters). SSH wird **nicht** benötigt – alles läuft über FTP und den Browser.

## Kurzfassung für ALL-INKL

Alle Einstellungen im **KAS** (Technische Verwaltung):

1. **Subdomain**: unter *Domain → Subdomains* die Subdomain `app.volleyorga.de` anlegen. Als Ziel einen eigenen, leeren Ordner wählen (z. B. `/app.volleyorga.de/`, **nicht** innerhalb des Ordners der Hauptdomain), PHP-Version 8.5 einstellen und das kostenlose Let's-Encrypt-Zertifikat (SSL-Schutz) für die Subdomain aktivieren. Das Zertifikat der Hauptdomain gilt nicht automatisch für die Subdomain.
2. **Datenbank**: unter *Datenbanken* eine MySQL-Datenbank anlegen; Server ist in der Regel `localhost`.
3. **Hochladen**: die ZIP-Datei in den Ordner der Domain laden und **dort entpacken** – fertig, nichts verschieben (Schritt 3 unten), also in den Ordner der Subdomain. Dann den Einrichtungsassistenten im Browser aufrufen.
4. **Cronjob**: unter *Tools → Cronjobs* einen Cronjob anlegen, im Feld *Log / Path* die geheime Adresse von der Abschlussseite des Assistenten eintragen (`https://app.volleyorga.de/cron/<token>`) und das kürzeste angebotene Intervall wählen. ALL-INKL ruft dabei eine Webadresse auf – genau dafür ist diese Adresse da. Falls das Paket keine Cronjobs enthält, lassen sie sich im Mitgliederbereich als Option dazubuchen.

> Die Menüpunkte im KAS können sich ändern; maßgeblich ist die [Anleitung von ALL-INKL zu Cronjobs](https://all-inkl.com/en/support/tutorials/kas/tools/cronjobs/setup_479.html).

## 1. Paket holen

Jeder Push auf `main` baut in GitHub Actions ein fertiges Paket: Repository → *Actions* → letzter Lauf von **CI** → Artefakt **volleyorga** herunterladen. (GitHub packt das Artefakt noch einmal in eine ZIP-Datei; darin liegt `volleyorga.zip`.)

`volleyorga.zip` enthält ohne zusätzlichen Oberordner:

- `.htaccess` – leitet jeden Aufruf nach `backend/public` weiter (und HTTP auf HTTPS), sodass `.env`, `vendor/`, `storage/` usw. nie aus dem Netz erreichbar sind,
- `backend/` inklusive aller PHP-Abhängigkeiten und der fertig gebauten App,
- `INSTALLATION.md` – diese Anleitung (wird ebenfalls nicht ausgeliefert).

Auf dem Server muss nichts gebaut oder verschoben werden.

## 2. Datenbank anlegen

Im Kundenmenü des Hosters eine MySQL-Datenbank mit Benutzer anlegen (Zeichensatz `utf8mb4`, Sortierung `utf8mb4_unicode_ci`). Server, Datenbankname, Benutzer und Passwort notieren.

## 3. Hochladen per FTP

1. `volleyorga.zip` in den Ordner laden, auf den die (Sub-)Domain zeigt (bei `app.volleyorga.de` z. B. `/app.volleyorga.de/`), und **dort entpacken** – z. B. mit dem Entpacken-Befehl des FTP-Programms oder des Dateimanagers beim Hoster. Alternativ vorher am PC entpacken und den Inhalt (`.htaccess`, `backend/`, `INSTALLATION.md`) hochladen; die versteckte Datei `.htaccess` muss dabei mitkommen.
2. Andere Dateien im Hauptordner (z. B. eine Platzhalter-`index.html` des Hosters) stören nicht – die `.htaccess` leitet alles in die App.
   > Wer mag, kann stattdessen das Startverzeichnis der Domain direkt auf `backend/public` setzen; die `.htaccess` im Hauptordner wird dann einfach nicht benutzt.
3. Für die Ordner `backend/storage` (mit allen Unterordnern) und `backend/bootstrap/cache` im FTP-Programm Schreibrechte für PHP setzen (meist `755`, falls das nicht reicht `775`).

## 4. Einrichtung im Browser

1. `https://app.volleyorga.de` aufrufen – die App leitet automatisch zum Einrichtungsassistenten (`/install`).
2. Der Assistent prüft die Voraussetzungen (HTTPS, PHP-Version, Erweiterungen, Schreibrechte). Rot markierte Punkte zuerst beheben.
3. **Einrichtungscode:** Der Assistent legt die Datei `backend/storage/app/setup-code.txt` an. Diese per FTP öffnen und den Code eintragen. So kann niemand Fremdes eine frisch hochgeladene Installation übernehmen.
4. Adresse, Datenbank, Mailversand (Standard: PHP `mail()`, optional SMTP), den ersten Verein und **dein Admin-Konto** (Name, E-Mail) eintragen und **Jetzt einrichten** klicken.
5. Der Assistent legt die Tabellen und den Verein an, macht dein Konto zusätzlich zum **Betreiber der Plattform** und schreibt `backend/.env` (inklusive App-Schlüssel und Cron-Token). Danach ist er nicht mehr erreichbar.
   > Kann PHP die Datei `backend/.env` nicht schreiben, zeigt der Assistent ihren Inhalt an. Dann diesen Text als `.env` speichern, per FTP nach `backend/` hochladen und `backend/storage/app/setup-code.txt` löschen.

6. Über **Zur Anmeldung** auf der Abschlussseite (oder `https://app.volleyorga.de/<kürzel>/login`) deine E-Mail-Adresse eingeben und den Anmeldelink aus dem Postfach öffnen. Danach legst du Teams an und verteilst Einladungslinks – andere Wege in die App gibt es nicht.

### Weitere Vereine

Weitere Vereine registrieren sich selbst über die Startseite (`https://app.volleyorga.de` → **Verein registrieren**). Du bekommst als Betreiber eine Mail und gibst den Verein unter `https://app.volleyorga.de/platform` frei; der Admin des Vereins erhält dann seinen Anmeldelink. Dort kannst du Vereine auch sperren und wieder aktivieren.

## 5. Cronjob

Die Abschlussseite des Assistenten zeigt beides an:

- **Webadresse** `https://app.volleyorga.de/cron/<token>` – für Hoster, deren Cronjobs eine URL aufrufen (z. B. ALL-INKL), oder einen externen Dienst wie cron-job.org. Die Adresse geheim halten.
- **oder Befehl** `php …/backend/artisan schedule:run` – für Hoster, deren Cronjobs Befehle ausführen.

Ideal ist ein Aufruf jede Minute. Seltener (z. B. alle 5 Minuten) funktioniert auch – Mails und Erinnerungen gehen dann entsprechend verzögert raus, tägliche Aufgaben laufen trotzdem zuverlässig.

Der Cronjob verschickt Mails und Push-Nachrichten (auch die Erinnerungen vor Antwortfristen), legt Serientermine an, spielt Datenbank-Updates ein und prüft täglich das SSL-Zertifikat.

**Dateien im Chat und in der Dokumentenablage** (Fotos, Videos, PDF, Office-/LibreOffice-Dateien): Die größte erlaubte Datei ist das Kleinste aus `CHAT_UPLOAD_MAX_MB` (Standard 50) und den PHP-Werten `upload_max_filesize` und `post_max_size`. Bei ALL-INKL stehen diese im KAS unter *Tools → PHP-Einstellungen* (oft nur 2–8 MB voreingestellt) – für Handyvideos z. B. beide auf `64M` setzen. Die Dateien liegen im Webspace unter `backend/storage/app/tenants/…` und zählen zum Speicherplatz des Tarifs; Fotos werden auf 1600 px verkleinert, Videos und Dokumente unverändert gespeichert.

**Push-Nachrichten** brauchen keine Einrichtung: Die Schlüssel (VAPID) erzeugt die App beim ersten Bedarf selbst und speichert sie verschlüsselt in der Datenbank. Der Server muss nur ausgehende HTTPS-Verbindungen zu den Push-Diensten von Google, Mozilla, Apple und Microsoft aufbauen dürfen (bei ALL-INKL der Fall). Wird der `APP_KEY` geändert, entstehen neue Schlüssel und alle Geräte müssen Push im Profil neu aktivieren.

## 6. Updates

Neues Paket im Hauptordner der Domain über die bestehenden Dateien entpacken (bzw. hochladen) und vorhandene Dateien überschreiben lassen. `backend/.env` und die Inhalte von `backend/storage/` sind nicht im Paket und bleiben erhalten. Neue Datenbank-Änderungen spielt der Cronjob innerhalb einer Minute automatisch ein.

## Hinweise

- **„Zugriff nur über HTTPS“ trotz https-Adresse:** Der Hoster beendet SSL dann in einem Proxy vor PHP. Proxies in privaten Netzen werden automatisch erkannt. Nutzt der Proxy öffentliche Adressen, in `backend/.env` `TRUSTED_PROXIES=*` ergänzen.
- `FORCE_HTTPS` in `.env` nie auf `false` setzen.
