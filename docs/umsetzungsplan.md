# Technischer Umsetzungsplan – Volleyball Plus

Grundlage: [`spezifikation.md`](spezifikation.md). Das Datenmodell steht in [`datenmodell.md`](datenmodell.md).
Status: **Vorschlag zur Freigabe** – Rahmenbedingung: Hosting mit **PHP und SQL** (klassisches Webhosting).

---

## 1. Architektur-Überblick

```
 Smartphone / Browser (PWA: statisches React-Frontend + Service Worker)
        │  HTTPS only (Zertifikat vom Hoster, z. B. Let's Encrypt)
        ▼
 ┌────────────────────────────────────────────────────┐
 │ Webhosting (Apache/Nginx + PHP-FPM)                │
 │                                                    │
 │  public/            ← Document Root                │
 │   ├─ index.html, assets/  (gebautes Frontend)      │
 │   └─ index.php      → Laravel-API  /api/v1/…       │
 │                                                    │
 │  Cronjob jede Minute: php artisan schedule:run     │
 │   → Mail-/Push-Warteschlange, Serientermine,       │
 │     Frist-Erinnerungen, SSL-Selbstprüfung          │
 └───────┬───────────────────────┬────────────────────┘
         ▼                       ▼
   MySQL 8                    storage/ (außerhalb Document Root)
                              Logos, Profilbilder
         │
         └── Mail: PHP mail() (Standard) oder SMTP (optional)
             Web-Push: VAPID direkt aus PHP, ohne Drittanbieter
```

Gebaut wird in GitHub Actions (Composer-Install, Frontend-Build, Tests). Das fertige Paket wird per FTP auf das Hosting übertragen – auf dem Server müssen weder Node.js noch Composer laufen, **SSH ist nicht nötig**: Die Einrichtung übernimmt ein Assistent im Browser (`/install`), Datenbank-Updates spielt der Cronjob nach jedem Upload automatisch ein.

## 2. Technologie-Stack

| Bereich | Wahl | Begründung |
|---|---|---|
| Backend | **PHP 8.5, Laravel 13** (nur als API) | läuft auf Standard-Webhosting; Migrationen, Policies, Mail, Queue, Scheduler, Übersetzungen eingebaut |
| Datenbank | MySQL 8, InnoDB, `utf8mb4` | Vorgabe Hosting |
| Warteschlange | Laravel-Queue mit `database`-Treiber, abgearbeitet per Cron (`schedule:run` → `queue:work --stop-when-empty`) | kein Redis, keine dauerhaft laufenden Prozesse nötig |
| Mail | Laravel-Mailer: Standard **`sendmail`/PHP-`mail()`**, umschaltbar auf **SMTP** in den Einstellungen | Vorgabe Abteilung |
| Push | `minishlink/web-push` (VAPID) | kostenlos; Android/Desktop und iOS ≥ 16.4 als installierte PWA |
| Bilder | Intervention Image (GD/Imagick) → WebP, max. 512 px | kleine Logos/Profilbilder, EXIF entfernen |
| Frontend | React 18 + Vite + `vite-plugin-pwa`, Tailwind CSS, Radix UI | Mobile-first-SPA, wird statisch ausgeliefert |
| Daten-Fetching | TanStack Query (`useInfiniteQuery`) | echtes Infinite Scroll, sofortige Anzeige der Zu-/Absage |
| i18n | Frontend `react-i18next` (`locales/de|en/*.json`), Backend Laravel `lang/de|en` | keine hartcodierten Texte; neue Sprache = neue Dateien |
| Diagramme | Recharts | Balkendiagramm Anwesenheit |
| Chat-Aktualisierung | kurzes Polling (alle 4–5 s bei geöffnetem Chat, sonst Push) | WebSockets/Dauerverbindungen laufen auf Webhosting meist nicht |
| Kalender | ICS-Feed pro Person (Token-URL) | kostenloser Kalender-Sync |
| Tests | PHPUnit/Pest (API, Mandantentrennung, Rechte), Vitest, Playwright (Mobile) | |

**Anforderungen an das Hosting:** PHP 8.5 mit `openssl`, `pdo_mysql`, `mbstring`, `gd` oder `imagick`, `gmp` oder `bcmath` (für Web-Push), `curl`; SSL-Zertifikat. Bevorzugt: Document Root auf `backend/public/` und Cronjob im Minutentakt. **Ausweichlösungen**, falls das nicht geht: (a) eine `.htaccess` im Hauptverzeichnis leitet alle Anfragen nach `public/` um und sperrt den Rest; (b) ohne Cron ruft ein externer Cron-Dienst eine geheime URL `/cron/<token>` auf, notfalls werden fällige Jobs am Ende normaler Seitenaufrufe abgearbeitet.

## 3. Mandantenfähigkeit (Multi-Tenant)

MySQL kennt keine Row-Level-Security. Die Trennung wird deshalb so umgesetzt:

1. **Jede fachliche Tabelle** hat `tenant_id` (NOT NULL, indiziert).
2. **Zusammengesetzte Fremdschlüssel** `(tenant_id, x_id) → x(tenant_id, id)` – InnoDB erzwingt damit, dass eine Zeile nie auf Daten einer anderen Abteilung zeigt.
3. **Globaler Eloquent-Scope** `BelongsToTenant`: jede Abfrage wird automatisch auf den aktuellen Mandanten gefiltert, neue Zeilen erhalten die `tenant_id` automatisch. Es gibt keinen Code-Pfad, der Mandantendaten ohne gesetzten Mandantenkontext lädt (sonst Exception).
4. **Pflicht-Test in der CI** für jedes Modell: Mandant A sieht keine einzige Zeile von Mandant B, auch nicht über verschachtelte Beziehungen und IDs in der URL.

- **Mandanten-Auflösung:** Pfad-basiert `https://<domain>/<abteilungs-slug>/…` – ein Zertifikat, keine Subdomains nötig.
- **Globale Tabellen** (ohne `tenant_id`): `tenants`, `accounts`, `auth_tokens`, `sessions`, `push_subscriptions`. Eine E-Mail kann in mehreren Abteilungen Mitglied sein; nach dem Login wählt man die Abteilung.
- **Plattform-Admin** legt Abteilungen an/sperrt sie, sieht aber keine Inhalte.

## 4. Sicherheit & SSL

- **Erzwingen:** `.htaccess`-Weiterleitung HTTP → HTTPS **und** eine Laravel-Middleware, die jeden Nicht-HTTPS-Request ablehnt (Prüfung von `$_SERVER['HTTPS']` bzw. vertrauenswürdigem Proxy-Header).
- **Header:** `Strict-Transport-Security` (2 Jahre, includeSubDomains), Content-Security-Policy, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`.
- **Cookies:** `Secure; HttpOnly; SameSite=Lax`, Prefix `__Host-`.
- **SSL-Selbstprüfung** (beim Setup und täglich per Cron): PHP baut eine TLS-Verbindung zur eigenen Domain auf (`stream_socket_client` mit `capture_peer_cert`), prüft Gültigkeit der Kette, Hostname, Restlaufzeit (Warnung < 14 Tage), und dass `http://` auf `https://` umleitet. Ergebnis auf einer Admin-Statusseite, bei Problemen Mail an die Admins. Der Setup-Assistent lässt sich ohne gültiges HTTPS nicht abschließen.
- **Magic-Link-Login:**
  - Token: 32 Byte zufällig, gespeichert nur als SHA-256-Hash, gültig 15 Minuten, einmalig verwendbar.
  - Rate-Limit pro E-Mail und IP; immer gleiche Antwort („Falls die Adresse bekannt ist …“), damit niemand herausfinden kann, welche Adressen registriert sind.
  - Der Link öffnet eine Bestätigungsseite mit Button „Jetzt anmelden“, damit Mail-Scanner, die Links vorab öffnen, ihn nicht verbrauchen.
  - **Session mit gleitender Laufzeit:** 90 Tage ab der letzten Nutzung; bei Aktivität wird das Ablaufdatum verlängert (höchstens einmal täglich in die DB geschrieben). Geräteliste mit „Abmelden“ im Profil.
- Rechteprüfung ausschließlich serverseitig über Laravel-Policies; das Frontend blendet nur aus.
- Uploads liegen außerhalb des Document Root und werden über einen PHP-Controller mit Rechteprüfung ausgeliefert; nur Bildtypen, serverseitig neu kodiert.
- DSGVO: Datenexport und Löschung pro Person, keine Tracker, keine externen Schriftarten/CDNs.

## 5. Rollen & Rechte

| Rolle | Ebene | Kern-Rechte |
|---|---|---|
| Admin | Abteilung | alles in allen Teams; Ligabaum, Hallen, Abteilungskasse, abteilungsweite Termine/Umfragen/Infos/Chats, Mail-Einstellungen |
| Trainer | Team (mehrere möglich) | Termine, Team-Einstellungen (inkl. Quernominierung), Einladungslinks, Trainingsgruppen, Quoten & Statistik, Info-Notizen, Teamkasse |
| Betreuer/Organisator | Team | Termine & Aufgaben organisieren, Teamkasse, Umfragen, Einladungslinks – **keine** Quoten/Statistik |
| Spieler | Team | eigene Zu-/Absagen, eigene Quote, Chat, Aufgaben, Umfragen |
| Elternteil | an Kind-Profil gekoppelt | handelt stellvertretend für das Kind (Zu-/Absage, Aufgaben, Chat), alleinige Befugnis über das Kinderprofil, legt fest, was das Kind selbst darf; endet automatisch mit dem 18. Geburtstag |

Rollen sind kombinierbar (Trainer in Herren 1, Spieler in Herren 2). Ein Erwachsener kann mehreren Teams angehören.

## 6. Fachliche Kernlogik

- **Startseite:** anstehende Termine aller eigenen Teams (bzw. der Kinder), Infobereich darüber, direkte Zu-/Absage-Buttons. Cursor-Pagination über `(starts_at, id)`, nachgeladen per `IntersectionObserver` (kein „Weiter“-Button).
- **Navigation:** Kopfzeile mit Abteilungslogo, Ausklappmenü oben rechts, keine Tab-Leiste.
- **Serientermine:** Serie mit Wiederholungsregel (RRULE, PHP-Bibliothek `rlanvin/php-rrule`); der Cron erzeugt Einzeltermine für ein rollierendes Fenster von 6 Monaten. Einzeltermine sind einzeln änderbar/absagbar.
- **Einladungslinks zum Team:** Trainer/Betreuer/Admin erzeugen einen Link (`https://<domain>/<slug>/join/<token>`), optional mit Ablaufdatum, maximaler Nutzungszahl und vorgegebener Rolle. Ablauf: Link öffnen → Name + E-Mail eingeben (oder bestehendes Konto) → Magic Link → Profil vervollständigen → **automatisch Mitglied im Team** (keine Freigabe nötig). Zusätzlich persönliche Einladungen an eine bestimmte E-Mail, z. B. damit ein Elternteil mit dem Profil des Kindes verknüpft wird. Links sind jederzeit widerrufbar, als QR-Code anzeigbar und per Messenger teilbar.
- **Quernominierung:**
  - Nur zwischen **Erwachsenenteams**, nur von unten nach oben in der Hierarchie, nur passend zum Team-Geschlecht (Herren ↔ männlich, Damen ↔ weiblich, Mixed ↔ beide). Jugendteams sind von der Hierarchie und der Spielerauswahl ausgenommen; maßgeblich ist, in welchen Teams der Spieler eingeteilt ist, nicht sein Alter.
  - Pro Spieler vom Trainer aktivierbar.
  - **Team-Einstellung** (Trainer): Standard, ab wann Quernominierte zusagen dürfen – z. B. „sofort“, „X Stunden vor Trainingsbeginn“ oder „nach Ablauf der Rückmeldefrist der Stammspieler“.
  - **Im einzelnen Termin überschreibbar** (eigener Zeitpunkt).
  - Keine Warteliste: Ist das Maximum erreicht, ist die Zusage geschlossen; sagt jemand ab, wird der Platz wieder frei.
- **Zu-/Absage:** Zusage, Absage oder Vielleicht. „Vielleicht“ zählt nicht für Teilnehmermaximum und Mindestteilnehmer. In den **Team-Einstellungen** legt der Trainer fest, ob ein Grund bei Absage (und optional bei Vielleicht) Pflicht ist und ob Mitspieler die Gründe sehen; Trainer und Admin sehen sie immer, Betreuer nur, wenn die Gründe für Mitspieler freigegeben sind.
- **Minderjährige:** Eltern legen pro Kind fest, was es selbst darf (eigener Login, Zu-/Absage, Chat, Aufgaben, Umfragen, einzelne Profilfelder). Alles andere erledigt das Elternteil. Zum 18. Geburtstag löst ein täglicher Job die Verknüpfung automatisch, das Kind erhält volle Rechte über sein Profil; beide werden per Mail informiert.
- **Zusage-Prüfung** (in einer DB-Transaktion mit `SELECT … FOR UPDATE` auf den Termin, damit das Limit nicht überbucht wird): Frist → Freigabezeitpunkt für Quernominierte → Teilnehmermaximum → (Ausbaustufe) Positions-Soll.
- **Mindestteilnehmerzahl:** pro Team einstellbar; Termin wird gelb markiert, solange die Zusagen darunter liegen.
- **Hierarchie:** Rang aus Liga (Ligabaum, vom Admin pflegbar) und Reihenfolge innerhalb derselben Liga.
- **Hallenkonflikte:** Beim Zuordnen eines Teams zu Halle und Zeit prüft der Server Überschneidungen und warnt (kein Blockieren).
- **Statistik:** Quote = Zusagen / Termine mit Rückmeldung im Zeitraum, zu denen der Spieler eingeladen war. **Zeitraum frei eingrenzbar** (Von–Bis), dazu Schnellwahl „letzte 4 Wochen“ und „Saison“. Sichtbarkeit serverseitig gefiltert (Spieler: nur eigene, Trainer/Admin: alle, Betreuer: keine). Balkendiagramm über alle Spieler eines Teams.
- **Wegbeschreibung:** `https://www.google.com/maps/dir/?api=1&destination=` + URL-kodierte Adresse.
- **Push auf iOS:** In iOS-Safari ohne Installation erscheint ein Hinweis „Zum Home-Bildschirm hinzufügen“; das Push-Opt-in wird erst in der installierten App angeboten.
- **Mail-Versand:** Standard PHP `mail()`; in den Admin-Einstellungen auf SMTP umstellbar (Host, Port, Verschlüsselung, Benutzer, Passwort – verschlüsselt gespeichert) mit „Testmail senden“.

## 7. Projektstruktur

```
volleyball-plus/
├─ backend/                 Laravel (API)
│  ├─ app/Models, app/Policies, app/Http/Controllers/Api
│  ├─ app/Support/Tenancy   Mandantenkontext, BelongsToTenant-Scope
│  ├─ database/migrations
│  ├─ lang/de, lang/en      Mail- und Push-Texte, Fehlermeldungen
│  └─ public/               Document Root (index.php + gebautes Frontend)
├─ frontend/                React-PWA (Vite)
│  ├─ src/features/         events, chat, cash, polls, stats, halls, profile, invites …
│  ├─ src/locales/de|en
│  └─ src/sw.ts             Service Worker (Push, Offline-Shell)
├─ deploy/                  Paket-Skript, .htaccess für Hosting ohne wählbaren Document Root
├─ .github/workflows/       Tests, Build, Deploy-Paket
└─ docs/
```

## 8. Umsetzungsphasen

| Phase | Inhalt | Ergebnis |
|---|---|---|
| **0 – Fundament** ✅ | Laravel + React-Gerüst, CI, Mandanten-Scope & Tests, i18n-Grundgerüst, HTTPS-Erzwingung & SSL-Selbstprüfung, Mail per `mail()`/SMTP, Cron-Ersatz-URL, Deploy-Paket, **Einrichtungsassistent im Browser** (ohne SSH), automatische DB-Updates per Cron | lauffähiges Gerüst auf dem Hosting |
| **1 – Login & Struktur** | Erstes Admin-Konto, Magic Link (mail()/SMTP), gleitende 90-Tage-Session, Abteilung, Teams, Personen, Rollen, **Einladungslinks**, Eltern-Verknüpfung, Profil inkl. Bild, Sprachwahl | Spieler können per Link beitreten |
| **2 – Termine & Zu-/Absage (MVP)** | Terminarten, Serien, Fristen, Startseite mit Infinite Scroll, Info-Termine, abteilungsweite Termine, drei Logo-Ebenen, Wegbeschreibung, Infobereich, ICS-Feed | **erste produktiv nutzbare Version** |
| **3 – Benachrichtigungen** | Web-Push, iOS-Installationshinweis, Frist-Erinnerungen, Einstellungen pro Kategorie | |
| **4 – Training** | Teilnehmermaximum, Mindestteilnehmer (gelb), Trainingsgruppen, Ligabaum, Hierarchie, Geschlechtsregel, Quernominierung mit Team-Standard & Termin-Override, SR-Lizenz, Positionsanzeige | |
| **5 – Statistik** | Quoten mit Sichtbarkeitsregeln, frei wählbarer Zeitraum, Balkendiagramm, Helfer-Statistik | |
| **6 – Aufgaben, Helferdienste, Umfragen** | Aufgaben zu Terminen, Helfertermine, Umfragen pro Team und teamübergreifend | |
| **7 – Chat** | Teamchats, Admin-Chats mit freier Auswahl, Themen/Unterkanäle, Polling + Push | |
| **8 – Kasse & Hallen** | Teamkassen, Abteilungskasse, Admin-Gesamtübersicht; Hallen, Zeiten, Belegungsplan mit Konfliktwarnung | |
| **9 – Ausbau** | positionsbasierte Nominierung, Datenexport, Feinschliff | |

## 9. Entscheidungen & offene Punkte

**Entschieden:**
- Hosting PHP 8.5 + MySQL → Laravel 13 + MySQL 8, Cron statt Hintergrundprozessen, Chat per Polling.
- Jugendteams sind von Hierarchie/Spielerauswahl ausgenommen; Alter bzw. Geburtsdatum ist optional; maßgeblich ist die Team-Einteilung.
- Erwachsene können mehreren Teams angehören.
- Quernominierung: Standard-Zeitpunkt in den Team-Einstellungen, im Termin überschreibbar, keine Warteliste.
- Login verlängert sich bei Nutzung (gleitend 90 Tage).
- Geschlecht: nur männlich/weiblich.
- Statistik-Zeitraum frei eingrenzbar.
- Einladungslinks für Spieler zum Team.
- Mail standardmäßig über PHP `mail()`, optional SMTP.
- Zu-/Absage mit „Vielleicht“; Pflicht-Grund und Sichtbarkeit der Gründe für Mitspieler stellt der Trainer pro Team ein.
- Kinder werden mit 18 automatisch vom Elternkonto gelöst; Eltern bestimmen, was das Kind selbst einstellen darf.
- Über einen Einladungslink ist man automatisch im Team.
- Kein SSH: Einrichtung per Browser-Assistent (geschützt durch Einrichtungscode aus einer Datei, die nur per FTP lesbar ist), Updates per FTP-Upload, Datenbank-Migrationen automatisch per Cron.
- Entwicklung auf `main`.

**Noch offen (blockiert nicht, Ausweichlösung siehe Abschnitt 2):**
1. Lässt sich beim Hosting der Document Root auf einen Unterordner setzen?
2. Gibt es Cronjobs im Minutentakt (sonst externer Cron-Dienst über die geheime Cron-Adresse)?
