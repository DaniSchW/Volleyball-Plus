# Technischer Umsetzungsplan – Volleyball Plus

Grundlage: [`spezifikation.md`](spezifikation.md). Das Datenmodell steht in [`datenmodell.md`](datenmodell.md).
Status: **Vorschlag zur Freigabe** – die Implementierung beginnt erst nach Abstimmung der offenen Punkte (Abschnitt 9).

---

## 1. Architektur-Überblick

```
 Smartphone / Browser (PWA)
        │  HTTPS only (HSTS, TLS 1.2+)
        ▼
 ┌──────────────────┐
 │ Caddy (Reverse-  │  automatische Let's-Encrypt-Zertifikate,
 │ Proxy, TLS)      │  HTTP → HTTPS-Redirect, HSTS
 └────────┬─────────┘
          ▼
 ┌──────────────────────────────────────────────┐
 │ App-Server (Node.js, TypeScript, Fastify)    │
 │  • REST-API (/api/v1/…)  • WebSocket (Chat)  │
 │  • liefert gebautes PWA-Frontend aus         │
 │  • Job-Worker (pg-boss): Mails, Push,        │
 │    Erinnerungen, Serientermine, Fristen      │
 └───────┬───────────────────┬──────────────────┘
         ▼                   ▼
   PostgreSQL 16        Datei-Speicher (lokales Volume
   (Row-Level-Security  oder S3-kompatibel, z. B. MinIO)
    pro Mandant)        für Logos & Profilbilder
         │
         └── SMTP-Anbieter (EU) für Magic-Links
             Web-Push (VAPID, ohne Drittanbieter-Konto)
```

Alles läuft als Docker-Compose-Stack auf einem eigenen Server (z. B. Hetzner/Netcup, DSGVO-freundlich in der EU). Keine laufenden API-Kosten (kein Google-Places, kein Push-Dienstleister, kein Zahlungsanbieter).

## 2. Technologie-Stack (Vorschlag)

| Bereich | Wahl | Begründung |
|---|---|---|
| Sprache | TypeScript (Front- & Backend) | eine Sprache, geteilte Typen/Validierung |
| Repo-Struktur | pnpm-Monorepo: `apps/web`, `apps/server`, `packages/shared` | gemeinsame Zod-Schemas, Enums, i18n-Keys |
| Frontend | React 18 + Vite + `vite-plugin-pwa` (Workbox) | schnelle Mobile-SPA, Service Worker für Offline-Shell & Push |
| UI | Tailwind CSS + Radix UI (barrierearme Primitives) | Mobile-first, kein schweres Design-Framework |
| Daten-Fetching | TanStack Query (inkl. `useInfiniteQuery`) | echtes Infinite Scroll, Caching, Optimistic Updates bei Zu-/Absage |
| i18n | `i18next` / `react-i18next`, JSON-Dateien `locales/de/*.json`, `locales/en/*.json` | keine hartcodierten Texte; neue Sprache = neuer Ordner. Server-Texte (Mails, Push) über dieselben Keys |
| Diagramme | Recharts | Balkendiagramm Anwesenheitsquote |
| Backend | Fastify + `@fastify/websocket` | schlank, schnell, WebSocket für Chat |
| ORM / Migrationen | Drizzle ORM + drizzle-kit | typsicher, SQL-nah (wichtig für RLS, Exclusion-Constraints) |
| Datenbank | PostgreSQL 16 | Row-Level-Security für Mandantentrennung, `tstzrange`, JSONB |
| Jobs | pg-boss (Queue in PostgreSQL) | keine zusätzliche Infrastruktur (kein Redis nötig) |
| Push | Web Push Protocol (`web-push`, VAPID) | kostenlos, funktioniert auf Android/Desktop und iOS ≥ 16.4 als installierte PWA |
| Mail | Nodemailer über SMTP | Magic-Link, optional Benachrichtigungen |
| Bilder | `sharp` (Server-seitig verkleinern, WebP) | kleine Profilbilder/Logos, EXIF entfernen |
| Kalender | ICS-Feed pro Person (Token-URL) | kostenloser Kalender-Sync (Abgrenzung zu SpielerPlus) |
| Tests | Vitest (Unit), Playwright (E2E, Mobile-Viewport) | |
| Betrieb | Docker Compose, Caddy, tägliche `pg_dump`-Backups | |

## 3. Mandantenfähigkeit (Multi-Tenant)

- **Mandant = Abteilung** (`tenant`). Jede fachliche Tabelle trägt `tenant_id NOT NULL`.
- **Durchsetzung in drei Schichten:**
  1. **Datenbank:** PostgreSQL Row-Level-Security auf jeder Mandantentabelle:
     `USING (tenant_id = current_setting('app.tenant_id')::uuid)`. Die App verbindet sich mit einer Rolle **ohne** `BYPASSRLS`.
  2. **Fremdschlüssel:** zusammengesetzte FKs `(tenant_id, id)` – eine Zeile kann technisch nicht auf Daten eines anderen Mandanten zeigen.
  3. **Anwendung:** Jeder Request öffnet eine Transaktion und setzt `SET LOCAL app.tenant_id = …` aus der Session. Kein Repository-Code ohne Mandantenkontext.
- **Mandanten-Auflösung:** Pfad-basiert `https://<domain>/<abteilungs-slug>/…` (ein Zertifikat, keine Wildcard-DNS nötig). Subdomains sind später nachrüstbar.
- **Globale Tabellen** (ohne RLS, nur für Login): `tenant`, `account`, `auth_token`, `session`. Eine E-Mail-Adresse kann in mehreren Abteilungen Mitglied sein; nach dem Login wählt man ggf. die Abteilung.
- **Super-Admin (Plattform)** kann nur Abteilungen anlegen/sperren, sieht aber keine Inhalte.
- Automatisierter Test: „Mandant A darf keine einzige Zeile von Mandant B lesen“ für jede Tabelle (Pflicht in CI).

## 4. Sicherheit & SSL

- Caddy terminiert TLS (Let's Encrypt, automatische Erneuerung), leitet Port 80 nur auf HTTPS um.
- **Selbstprüfung der App:**
  - Middleware lehnt jeden Request ab, der nicht über HTTPS kam (`X-Forwarded-Proto` von vertrauenswürdigem Proxy), außer im Dev-Modus.
  - Header: `Strict-Transport-Security: max-age=63072000; includeSubDomains; preload`, CSP, `X-Content-Type-Options`, `Referrer-Policy`.
  - Cookies: `Secure; HttpOnly; SameSite=Lax`, Prefix `__Host-`.
  - Start- und täglicher Health-Check: App ruft die eigene öffentliche URL auf, prüft Zertifikatskette, Gültigkeit (> 14 Tage Restlaufzeit), Hostname und dass `http://` auf `https://` umleitet. Ergebnis auf einer Admin-Statusseite; bei Fehler Warnung an Admins (Mail/Push).
- **Magic-Link-Login:**
  - Token: 32 Byte zufällig, in der DB nur als SHA-256-Hash, gültig 15 Minuten, einmalig verwendbar.
  - Rate-Limit pro E-Mail und IP; Antwort immer gleich („Falls die Adresse bekannt ist, wurde ein Link verschickt“) → keine Nutzer-Enumeration.
  - Session: zufällige ID (gehasht gespeichert), **Laufzeit 90 Tage**; Nutzer kann Geräte/Sessions im Profil einsehen und abmelden.
  - Schutz gegen Mail-Scanner, die Links „vorklicken“: Link öffnet eine Bestätigungsseite mit Button „Jetzt anmelden“ (POST).
- Rechteprüfung ausschließlich serverseitig (zentrale Policy-Funktion `can(actor, action, resource)`), Frontend blendet nur aus.
- DSGVO: Datenexport & Löschung pro Person, Auftragsverarbeitung nur EU-Hosting/Mail, keine Tracker.

## 5. Rollen- & Rechtekonzept

| Rolle | Ebene | Kern-Rechte |
|---|---|---|
| Admin | Abteilung | alles in allen Teams; Ligabaum, Hallen, Abteilungskasse, abteilungsweite Termine/Umfragen/Infos/Chats |
| Trainer | Team (mehrere möglich) | Termine, Quernominierung, Trainingsgruppen, Quoten & Statistik, Info-Notizen, Teamkasse |
| Betreuer/Organisator | Team | Termine & Aufgaben organisieren, Teamkasse, Umfragen – **keine** Quoten/Statistik anderer |
| Spieler | Team | eigene Zu-/Absagen, eigene Quote, Chat, Aufgaben, Umfragen |
| Elternteil | an Kind-Profil gekoppelt | handelt stellvertretend für das Kind (Zu-/Absage, Aufgaben, Chat) und hat die alleinige Befugnis über das Kinderprofil |

Rollen sind additiv (z. B. Trainer in Herren 1 und Spieler in Herren 2). Die Rechte-Matrix wird als Code in `packages/shared/permissions.ts` gepflegt und vollständig getestet.

## 6. Fachliche Kernlogik (Auszug)

- **Startseite:** Liste aller anstehenden Termine aller eigenen Teams (bzw. der Kinder), sortiert nach Beginn, Cursor-Pagination (`starts_at, id`) für Infinite Scroll via `IntersectionObserver`. Direkte Zu-/Absage-Buttons in der Liste. Darüber der Infobereich.
- **Navigation:** Header mit Abteilungslogo und Ausklappmenü oben rechts; keine Tab-Leiste.
- **Serientermine:** Serie mit RRULE (RFC 5545, Lib `rrule`); Einzeltermine werden für ein rollierendes Fenster (z. B. 6 Monate) materialisiert, Ausnahmen (Ausfall, Verschiebung) pro Einzeltermin.
- **Zusage-Logik bei Trainings** (serverseitig in einer Transaktion mit Zeilensperre, damit das Limit nicht überbucht wird):
  1. Frist abgelaufen? → abgelehnt.
  2. Teilnehmermaximum erreicht? → Zusage geschlossen (Absage weiter möglich; frei werdender Platz öffnet wieder).
  3. Positions-Soll (Ausbaustufe) für die Position des Spielers voll? → Zusage für diese Position geschlossen.
  4. Quernominierte dürfen zusagen, wenn Plätze frei sind (optional: erst ab X Stunden vor Termin – offene Frage).
- **Mindestteilnehmerzahl:** pro Team einstellbar; Termin wird gelb markiert, solange Zusagen < Minimum.
- **Quernominierung:** nur von unten nach oben in der Hierarchie, nur passend zum Team-Geschlecht (Herren ↔ männlich, Damen ↔ weiblich, Mixed ↔ alle), pro Spieler aktivierbar.
- **Hierarchie:** Rang ergibt sich aus der Liga (Ligabaum, admin-pflegbar) plus Reihenfolge innerhalb derselben Liga.
- **Hallenkonflikte:** Beim Zuordnen eines Teams zu Halle + Zeit prüft der Server Überschneidungen (`tstzrange`/Zeitintervall-Overlap) und zeigt eine Warnung, blockiert aber nicht.
- **Statistik:** Quote = Zusagen / Termine mit Rückmeldepflicht im Zeitraum, für die der Spieler eingeladen war. Zeitraum frei wählbar (Vorgaben: 4 Wochen, Saison, benutzerdefiniert). Sichtbarkeit wird serverseitig gefiltert (Spieler: nur eigene; Trainer/Admin: alle; Betreuer: keine).
- **Wegbeschreibung:** `https://www.google.com/maps/dir/?api=1&destination=` + `encodeURIComponent(adresse)` – keine API.
- **Push auf iOS:** Hinweisbanner mit Anleitung „Zum Home-Bildschirm hinzufügen“, wenn iOS-Safari erkannt und App nicht im Standalone-Modus läuft; Push-Opt-in erst danach anbieten.

## 7. Projektstruktur

```
volleyball-plus/
├─ apps/
│  ├─ web/                 React-PWA
│  │  ├─ src/features/     events, chat, cash, polls, stats, halls, profile …
│  │  ├─ src/locales/de|en Übersetzungsdateien
│  │  └─ src/sw.ts         Service Worker (Push, Offline-Shell)
│  └─ server/
│     ├─ src/modules/      auth, tenants, teams, events, rsvp, chat, …
│     ├─ src/db/           Drizzle-Schema, Migrationen, RLS-Policies
│     └─ src/jobs/         Mail, Push, Serien, Fristen-Erinnerungen
├─ packages/shared/        Zod-Schemas, Enums, Berechtigungen, i18n-Keys
├─ infra/                  docker-compose.yml, Caddyfile, Backup-Skripte
└─ docs/                   Spezifikation, Plan, Datenmodell
```

## 8. Umsetzungsphasen

| Phase | Inhalt | Ergebnis |
|---|---|---|
| **0 – Fundament** | Monorepo, CI (Lint, Typecheck, Tests), Docker/Caddy, DB-Schema-Basis mit RLS, i18n-Grundgerüst, HTTPS-Selbstprüfung | lauffähiges Gerüst |
| **1 – Login & Struktur** | Magic-Link, 90-Tage-Session, Abteilung, Teams, Personen, Rollen, Eltern-Verknüpfung, Profil (alle Felder, Bild-Upload), Sprachwahl | Nutzer können sich anmelden und Teams sehen |
| **2 – Termine & Zu-/Absage (MVP)** | Terminarten, Serien, Fristen, Startseite mit Infinite Scroll, Zu-/Absage, Info-Termine, abteilungsweite Termine, Logos (3 Ebenen), Wegbeschreibung, Infobereich, ICS-Feed | **erste produktiv nutzbare Version** |
| **3 – Benachrichtigungen** | Web-Push, Install-Hinweis iOS, Erinnerungen vor Fristablauf, Benachrichtigungseinstellungen | |
| **4 – Training-Features** | Teilnehmermax., Mindestteilnehmer (gelb), Trainingsgruppen, Ligabaum, Hierarchie, Geschlechtsregel, Quernominierung, SR-Lizenz, Positionsanzeige | |
| **5 – Statistik** | Quoten mit Sichtbarkeitsregeln, Balkendiagramm, Zeitraumwahl, Helfer-Statistik | |
| **6 – Aufgaben, Helferdienste, Umfragen** | Aufgaben zu Terminen, Helfertermine, Umfragen pro Team und teamübergreifend | |
| **7 – Chat** | Teamchats, Admin-Chats mit freier Auswahl, Themen/Unterkanäle, WebSocket, Push bei neuen Nachrichten | |
| **8 – Kasse & Hallen** | Teamkassen, Abteilungskasse, Admin-Gesamtübersicht; Hallen, Zeiten, Belegungsplan mit Konfliktwarnung | |
| **9 – Ausbau** | positionsbasierte Nominierung, Datenexport, Feinschliff | |

Reihenfolge der Phasen 5–8 ist flexibel und kann nach Priorität der Abteilung getauscht werden.

## 9. Offene Fragen (bitte klären)

1. **Hosting & Domain:** Gibt es schon einen Server/eine Domain? Welcher SMTP-Anbieter für die Magic-Link-Mails?
2. **Jugendteams in der Hierarchie:** Laut Beispiel spielt ein Spieler in U16, U18 *und* der untersten Herrenmannschaft. Vorschlag: Jugend bildet einen eigenen Ast; die Regel „kein Spielen in niedrigeren Teams“ gilt nur innerhalb eines Astes (Erwachsene / Jugend), und Jugend → Erwachsene zählt als „von unten nach oben“. Passt das?
3. **Mehrere Stammteams:** Darf ein erwachsener Spieler regulär in zwei Erwachsenenteams Mitglied sein, oder nur in einem plus Quernominierungen?
4. **Quernominierte Spieler:** Dürfen sie sofort zusagen (solange Plätze frei) oder erst ab einem Zeitpunkt (z. B. 24 h vor Training), damit Stammspieler Vorrang haben? Gibt es eine Warteliste?
5. **Zu-/Absage-Status:** Nur „Zusage/Absage“ oder zusätzlich „Vielleicht“ und Absagegrund (Pflicht-/Freitext)?
6. **Session-Laufzeit:** 90 Tage fest ab Login, oder gleitend (bei jeder Nutzung verlängert)?
7. **Kinderprofil:** Darf das minderjährige Kind einen eigenen Login haben (z. B. für Chat und eigene Zusagen), wobei die Eltern Profiländerungen entscheiden? Ab welchem Alter? Mit 18 automatisch Ablösung vom Elternkonto?
8. **Geschlecht im Profil:** Für die Quernominierungsregel braucht das Profil ein Feld (männlich/weiblich/divers). Wie sollen „divers“-Spieler bei Herren-/Damenteams behandelt werden?
9. **Saison-Definition:** Saison z. B. 1. Juli – 30. Juni, oder pro Abteilung frei einstellbar?
10. **Repo-Zweig:** Das Repository war leer; die Dokumente liegen zunächst auf einem Feature-Branch. Soll künftig direkt auf `main` gearbeitet werden?
