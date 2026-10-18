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
 │  Cronjob jede Minute: URL /cron/<token> (ALL-INKL) │
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
| Push | `minishlink/web-push` (VAPID, ohne gmp/bcmath) | kostenlos; Android/Desktop und iOS ≥ 16.4 als installierte PWA |
| Bilder | Intervention Image (GD/Imagick) → WebP, max. 512 px | kleine Logos/Profilbilder, EXIF entfernen |
| Frontend | React 18 + Vite + `vite-plugin-pwa`, Tailwind CSS, Radix UI | Mobile-first-SPA, wird statisch ausgeliefert |
| Daten-Fetching | TanStack Query (`useInfiniteQuery`) | echtes Infinite Scroll, sofortige Anzeige der Zu-/Absage |
| i18n | Frontend `react-i18next` (`locales/de|en/*.json`), Backend Laravel `lang/de|en` | keine hartcodierten Texte; neue Sprache = neue Dateien |
| Diagramme | Recharts | Balkendiagramm Anwesenheit |
| Chat-Aktualisierung | kurzes Polling (alle 4–5 s bei geöffnetem Chat, sonst Push) | WebSockets/Dauerverbindungen laufen auf Webhosting meist nicht |
| Kalender | ICS-Feed pro Person (Token-URL) | kostenloser Kalender-Sync |
| Tests | PHPUnit/Pest (API, Mandantentrennung, Rechte), Vitest, Playwright (Mobile) | |

**Anforderungen an das Hosting:** PHP 8.5 mit `openssl`, `pdo_mysql`, `mbstring`, `gd` oder `imagick`, `curl`; SSL-Zertifikat. Bevorzugt: Document Root auf `backend/public/`; Ausweichlösung: eine `.htaccess` im Hauptverzeichnis leitet alle Anfragen nach `public/` um und sperrt den Rest. Cron: entweder als URL-Aufruf der geheimen Adresse `/cron/<token>` (ALL-INKL, externe Cron-Dienste) oder als Befehl `php artisan schedule:run`.

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
| **1 – Login & Struktur** ✅ | Erstes Admin-Konto, Magic Link (mail()/SMTP), gleitende 90-Tage-Session, Abteilung, Teams, Personen, Rollen, **Einladungslinks**, Eltern-Verknüpfung, Profil inkl. Bild, Sprachwahl | Spieler können per Link beitreten |
| **2 – Termine & Zu-/Absage (MVP)** ✅ | Terminarten, Serien, Fristen, Startseite mit Infinite Scroll, Info-Termine, abteilungsweite Termine, drei Logo-Ebenen, Wegbeschreibung, Infobereich, ICS-Feed | **erste produktiv nutzbare Version** |
| **3 – Benachrichtigungen** ✅ | Web-Push, iOS-Installationshinweis, Frist-Erinnerungen, Einstellungen pro Kategorie | |
| **4 – Training** ✅ | Teilnehmermaximum, Mindestteilnehmer (gelb), Trainingsgruppen, Ligabaum, Hierarchie, Geschlechtsregel, Quernominierung mit Team-Standard & Termin-Override, SR-Lizenz, Positionsanzeige | |
| **5 – Spielplan-Import** ✅ | Spieltage je Mannschaft aus Kalenderdatei importieren: **.ics** (iCalendar) und **CSV** (z. B. Spielplan-Export des Verbands/SAMS); Vorschau mit Auswahl, Gegner automatisch anlegen, Heim/Auswärts und Halle erkennen, erneuter Import aktualisiert statt doppelt anzulegen | Saison-Spielplan in Minuten statt Handarbeit |
| **6 – Statistik** ✅ | Quoten mit Sichtbarkeitsregeln, frei wählbarer Zeitraum, Balkendiagramm, Helfer-Statistik (mit Phase 7) | |
| **7 – Aufgaben, Helferdienste, Umfragen** ✅ | Aufgaben zu Terminen, Helfertermine, Umfragen pro Team und teamübergreifend | |
| **8 – Chat** ✅ | Teamchats, Admin-Chats mit freier Auswahl, Themen/Unterkanäle, Polling + Push | |
| **9 – Kasse & Hallen** | Teamkassen, Abteilungskasse, Admin-Gesamtübersicht; Hallen, Zeiten, Belegungsplan mit Konfliktwarnung | |
| **10 – Ausbau** | positionsbasierte Nominierung, Datenexport, Feinschliff | |

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
- Hosting bei **ALL-INKL**: Cronjobs rufen dort eine Webadresse auf → Hauptweg ist `/cron/{token}`, Intervall jede Minute. Geplante Aufgaben dürfen daher nicht von einer exakten Minute abhängen und müssen in das Zeitlimit eines Webaufrufs passen.
- Kontaktdaten: Betreuer sehen Details nur per Freigabe (wie Mitspieler); Trainer und Admins immer.
- Termine anlegen: Trainer, Betreuer, Admins; abteilungsweite Termine nur Admins. Eingeladen ist das ganze Team (Spieler, Trainer, Betreuer).
- Serientermine wöchentlich an Wochentagen (jede oder jede zweite Woche), sechs Monate rollierend voraus.
- Gemeinsame Termine mehrerer Teams: es gilt jeweils die strengere Team-Einstellung (Pflicht-Grund, Namen/Anzahl, Sichtbarkeit der Gründe).
- Entwicklung auf `main`.

- Registrierung ausschließlich über Einladungslinks; Login-Links gehen nur an bestehende Mitglieder.
- Profil: Standardangaben sehen alle Mitglieder; Details (Telefon, E-Mail, Adresse, Geburtstag) nur per Freigabe der Person – Trainer ihrer Teams, Admins und Eltern sehen sie immer.
- Sichtbarkeit der Zu-/Absagen (Namen oder nur Anzahl) legt der Trainer pro Team fest.
- Hierarchie (Phase 4, geändert): Rang aus der Liga (Ebene) und dem Rang in der Liga; Jugendteams und Teams ohne Liga stehen außerhalb. Spieler eines höheren Teams dürfen in einem niedrigeren Team derselben Art (Herren/Damen/Mixed) mittrainieren, aber nicht an dessen Spieltagen teilnehmen (keine Einladung zu Spielen). Quernominierung vergleicht über alle Arten (ein Mixed-Team darf aus niedrigeren Herren- und Damenteams einladen), dazu die Geschlechtsregel.
- Spieler zuordnen: Jeder Trainer kann Personen der ganzen Abteilung direkt in sein Team holen; sie erscheinen sofort. Wer über einen Einladungslink eines weiteren Teams beitritt und schon angemeldet ist, ist ebenfalls sofort im Team.
- Quernominierte Gäste: nur zu Team-Trainings (nicht Spiele/Gruppentrainings), Absage jederzeit, Zusage ab Freigabe; bei Freigabe „ab Rückmeldefrist“ bis Trainingsbeginn. Trainer/Admins verwalten die Gäste, Betreuer sehen sie.
- Trainingsgruppen: Gruppentrainings laden nur die Gruppe plus Trainer und Betreuer ein.
- Schiedsrichter-Lizenz: trägt die Person selbst (Kinder: Eltern) oder ein Admin ein; Trainer sehen sie im Kader mit Ablaufhinweis (60 Tage).
- Spielplan-Import (Phase 5): je Mannschaft (Termine-Tab → „Spielplan importieren“), durch Trainer, Betreuer und Admins. Formate: iCalendar (.ics/.ical, z. B. SAMS-Kalenderexport; Zeilenumbrüche, Zeitzonen, Escapes nach RFC 5545) und SAMS-CSV (Semikolon, Windows-1252 oder UTF-8). Ablauf: Datei hochladen → Vorschau → Auswahl → Übernehmen; die Datei wird nicht gespeichert. Der eigene Teamname in der Datei wird erkannt (häufigster Name) bzw. gewählt und am Team gemerkt (`teams.import_name`). Heim/Auswärts: CSV über „Gastgeber“, .ics über die Reihenfolge „Heim vs. Gast“. Halle: Name als Ort, vollständige Angabe als Adresse (Routenplaner). CSV-Zusatzinfos (Spieltag, Spielrunde, Schiedsgericht, Ergebnis) kommen in die Beschreibung; ohne Endzeit 2 Stunden. Einträge, bei denen das Team nur das Schiedsgericht stellt, werden zu Zusatzterminen „Schiedsgericht: A – B“. Vergangene Spiele werden angezeigt, aber nicht vorausgewählt. Gegner werden über den Namen zugeordnet oder angelegt. Erneuter Import: Abgleich über `events.import_key` (UID bzw. Saison+Spielrunde+Spielnummer), sonst über gleiche Anstoßzeit und Gegner (z. B. erst .ics, dann CSV); geändert werden nur Zeit, Halle, Heim/Auswärts und Gegner (mit Benachrichtigung „Termin geändert“), von Hand geänderte Titel/Beschreibungen bleiben. Spiele, die in der neuen Datei fehlen, werden nur gelistet, nicht abgesagt. Neue Spiele lösen eine einzige Sammelnachricht aus („Herren 1: 14 neue Termine im Spielplan“). Max. 1 MB / 300 Termine pro Datei.
- Statistik (Phase 6): je Team unter „Statistik“. Gezählt werden vergangene, nicht abgesagte Termine mit Rückmeldung des Teams im Zeitraum, zu denen die Person eingeladen war; Quote = Zusagen / Einladungen. Schnellwahl letzte 4 Wochen und Saison (ab `season_start_month`), freier Zeitraum, Filter nach Terminart. Trainer und Admins sehen alle Spieler als Balkendiagramm (absteigend nach Quote) samt Einzelterminen („wer hat wann geantwortet“, „nach Frist“); Spieler und Eltern nur die eigene Quote; Betreuer keine. Reine Trainer/Betreuer des Teams erscheinen nicht; ehemalige Spieler und Gäste schon (markiert).
- Aufgaben (Phase 7): Zu jedem Termin legen Trainer, Betreuer und Admins Aufgaben mit Plätzen an („Fahrer“ ×3). Eingeladene übernehmen selbst (Eltern für Kinder, Kinder nur mit „Aufgaben“-Freigabe) bis Terminbeginn und können wieder austragen; Verwalter tragen jeden Eingeladenen ein oder aus. Plätze werden unter Zeilensperre gezählt. Namen der Übernehmenden sehen alle Eingeladenen. Seite „Aufgaben“: eigene kommende Aufgaben und Helferdienste mit freien Plätzen.
- Helferdienst (Phase 7): eigene Terminart (🤝) ohne Zu-/Absage – mitgemacht wird über die Aufgaben. Helfer-Statistik je Team im Statistik-Tab: übernommene Aufgaben je Person im Zeitraum (Helferdienste und sonstige getrennt), auch für kommende Termine; Sichtbarkeit wie bei den Quoten (Trainer/Admin alle, sonst nur die eigenen, Betreuer keine). Die Schnellwahl „Saison“ reicht jetzt bis Saisonende (Quoten zählen ohnehin nur vergangene Termine).
- Umfragen (Phase 7): Trainer und Betreuer für ihre Teams, Admins auch für alle Teams. Optionen: Mehrfachauswahl, anonym, eigene Textantwort, Frist; vorzeitig beenden, löschen. Abstimmen dürfen alle Personen der Teams (Eltern für Kinder, Kinder nur mit Freigabe), Antwort änderbar bis zum Ende. Ergebnis sehen Abstimmende, Verwalter und nach dem Ende alle; Namen nur bei nicht anonymen Umfragen. Neue Umfragen werden per Push gemeldet (eigene Kategorie, abschaltbar); offene, unbeantwortete Umfragen erscheinen als Hinweis auf der Startseite.
- Chat (Phase 8): Jedes Team hat automatisch einen Chat (angelegt beim ersten Aufruf). Lesen und schreiben dürfen die Logins hinter den Personen des Teams – Kinder nur mit eigener Anmeldung und „Chat“-Freigabe, Eltern immer (Anzeige „Eva Eltern · Kim“). Admins sehen alle Chats und legen eigene Chats mit frei gewählten Mitgliedern an (z. B. Abteilungsleitung). Modus je Chat: Gruppenchat oder mit Themen (Telegram-Stil); beim Umstellen landen bisherige Nachrichten im Thema „Allgemein“. Themen anlegen und Modus ändern: Moderatoren (Admins; im Teamchat Trainer und Betreuer; im eigenen Chat der Ersteller). Nachrichten: antworten, eigene bearbeiten, löschen (eigene; Moderatoren alle) – gelöschte bleiben als „Nachricht gelöscht“ stehen. Ein geöffneter Chat fragt alle 4 s nach neuen und geänderten Nachrichten (`after` = letzte ULID, `since` = Serverzeit); Lesestand je Chat/Thema für die Ungelesen-Zähler. Push (Kategorie „Chat-Nachrichten“, abschaltbar) an alle anderen, außer wer den Chat in den letzten 60 s offen hatte; Benachrichtigungen eines Chats ersetzen sich auf dem Gerät.
- Chat-Anhänge: Fotos (jpg, png, webp, gif; auf 1600 px verkleinert, ohne Standortdaten), Videos (mp4, mov, m4v, webm; unverändert, abspielbar mit Spulen dank Byte-Ranges, zusätzlich Download-Link), PDF, Word/Excel/PowerPoint (docx, xlsx, pptx, doc, xls, ppt), LibreOffice (odt, ods, odp, odg), rtf, csv, txt. Erlaubt nur, wenn Dateiendung **und** erkannter Inhalt passen. Bis 10 Dateien je Nachricht; Größe laut `CHAT_UPLOAD_MAX_MB` und PHP-Limits. Erst hochladen (mit Fortschritt), dann mit der Nachricht senden; nie gesendete Uploads werden nach einem Tag gelöscht, Dateien gelöschter Nachrichten sofort. Abruf nur über den Chat mit dessen Rechteprüfung (nicht über die allgemeine Datei-Route); Fotos, Videos und PDF werden im Browser angezeigt, andere Dokumente heruntergeladen.
- Benachrichtigungen (Phase 3): Kategorien neue Termine, Änderungen/Absagen, Erinnerung, neue Infos. Standard: Push für alles, E-Mail nur bei Änderungen/Absagen; pro Kategorie und Abteilung im Profil einstellbar. Erinnerung einmal 24 h vor der Frist (sonst vor Beginn) an alle ohne Antwort. Eine neue Trainingsreihe wird einmal gemeldet, nicht je Termin. Versand über die Warteschlange im Cron-Lauf, also bis zu einer Cron-Runde verzögert.
