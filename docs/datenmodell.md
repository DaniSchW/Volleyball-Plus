# Datenmodell – Volleyball Plus

PostgreSQL 16. Konventionen:

- Primärschlüssel `id uuid` (UUIDv7, zeitlich sortierbar).
- **Jede Mandantentabelle** hat `tenant_id uuid NOT NULL`, einen Unique-Index `(tenant_id, id)` und RLS-Policy `tenant_id = current_setting('app.tenant_id')::uuid`. Fremdschlüssel innerhalb eines Mandanten sind **zusammengesetzt**: `(tenant_id, x_id) → x(tenant_id, id)`. Damit ist ein mandantenübergreifender Verweis technisch unmöglich.
- Zeitstempel `created_at`, `updated_at` (timestamptz); Zeiten in UTC, Anzeige in der Zeitzone des Mandanten.
- Geldbeträge in **Cent** (`bigint`), nie als Float.
- Weiche Löschung (`deleted_at`) nur wo Historie nötig ist (Personen, Termine, Buchungen).
- Enums werden als PostgreSQL-Enums oder `text` + CHECK abgebildet und im Code zentral in `packages/shared` definiert.

Legende: 🌐 = global (kein Mandant), 🏢 = mandantenbezogen.

---

## 1. Plattform, Login, Sessions

### 🌐 `tenant` – Abteilung (Dach-Account)
| Feld | Typ | Hinweis |
|---|---|---|
| id | uuid | |
| slug | text unique | URL-Pfad, z. B. `ssv-ulm-volleyball` |
| name | text | |
| logo_file_id | uuid → file | globales Abteilungslogo (Logo-Ebene 1) |
| default_locale | text | `de` |
| timezone | text | `Europe/Berlin` |
| season_start_month | smallint | z. B. 7 (= Juli) für Statistik „Saison“ |
| status | enum `active`, `suspended` | |

### 🌐 `account` – Login-Identität
| Feld | Typ | Hinweis |
|---|---|---|
| id | uuid | |
| email | citext unique | einziges Login-Merkmal |
| locale | text | `de` / `en`, Standard `de` |
| is_platform_admin | bool | darf nur Mandanten verwalten |
| last_login_at | timestamptz | |

### 🌐 `auth_token` – Magic-Link
`id`, `email`, `token_hash` (bytea, SHA-256), `expires_at` (+15 min), `used_at`, `requested_ip`, `user_agent`.

### 🌐 `session`
`id`, `account_id`, `token_hash`, `created_at`, `expires_at` (+90 Tage), `last_seen_at`, `user_agent`, `revoked_at`.

### 🌐 `push_subscription`
`id`, `account_id`, `endpoint` (unique), `p256dh`, `auth`, `user_agent`, `created_at`, `last_success_at`.

---

## 2. Mitgliedschaft, Personen, Rollen

Trennung zwischen **Account** (wer sich einloggt) und **Person** (Spielerprofil innerhalb einer Abteilung). Ein Kind ist eine Person ohne (oder mit eingeschränktem) eigenen Account; ein Elternteil ist ein Account, der mit der Person des Kindes verknüpft ist.

### 🏢 `tenant_member` – Account in einer Abteilung
| Feld | Typ | Hinweis |
|---|---|---|
| tenant_id, id | | |
| account_id | uuid → account | unique (tenant_id, account_id) |
| is_admin | bool | Rolle **Admin** |
| status | enum `invited`, `active`, `disabled` | |

### 🏢 `person` – zentrales Spielerprofil
| Feld | Typ | Hinweis |
|---|---|---|
| first_name, last_name | text | |
| jersey_number | smallint null | |
| birthday | date null | Minderjährigkeit wird daraus berechnet |
| gender | enum `male`, `female`, `diverse` | für Quernominierungsregel |
| phone | text null | |
| email | citext null | Kontaktadresse (nicht zwingend = Login) |
| street, postal_code, city, country | text null | Postadresse |
| primary_position | enum `position` null | |
| secondary_positions | `position[]` | |
| height_cm | smallint null | Körpergröße |
| reach_height_cm | smallint null | Reichhöhe (stehend) |
| jump_reach_cm | smallint null | Sprung-Reichhöhe (Angriff) |
| avatar_file_id | uuid → file null | |
| deleted_at | timestamptz null | |

`position` = `setter` (Zuspiel), `opposite` (Diagonal), `middle` (Mittelblock), `outside` (Außenangriff), `libero`, `universal`.

### 🏢 `person_account` – wer handelt für welche Person
| Feld | Typ | Hinweis |
|---|---|---|
| person_id | → person | |
| tenant_member_id | → tenant_member | |
| relation | enum `self`, `guardian` | `guardian` = Eltern-Account |
| can_edit_profile | bool | bei Kind mit Eltern: nur `guardian` = true (alleinige Befugnis) |

Unique `(person_id, tenant_member_id)`. Ein Account hat höchstens eine `self`-Person pro Abteilung, kann aber mehrere Kinder (`guardian`) haben. Die Startseite zeigt Termine aller verknüpften Personen.

### 🏢 `referee_license` – Schiedsrichter-Lizenz
`person_id`, `level` (text, z. B. „D“, „C“, „BK“), `license_number` null, `valid_until` date. Wird Trainern im Kader angezeigt, inkl. Hinweis bei Ablauf < 60 Tage.

---

## 3. Ligabaum, Teams, Hierarchie

### 🏢 `league` – admin-pflegbarer Ligabaum
| Feld | Typ | Hinweis |
|---|---|---|
| parent_id | → league null | Baumstruktur (z. B. Verband → Ebene → Staffel) |
| name | text | „1. Bundesliga“, „Regionalliga Süd“, „Bezirksklasse“ … |
| level | smallint | 1 = höchste Spielklasse; daraus Hierarchie-Rang |
| branch | enum `adult`, `youth` | Erwachsene / Jugend (siehe offene Frage 2) |
| sort_order | int | |

Vorlage beim Anlegen eines Mandanten: Standard-Ligabaum (1. BL … Kreisliga), frei anpassbar.

### 🏢 `team`
| Feld | Typ | Hinweis |
|---|---|---|
| name | text | „Herren 1“, „U18 männlich“ |
| gender | enum `men`, `women`, `mixed` | Geschlechtsart |
| league_id | → league null | |
| rank_in_league | smallint | Reihenfolge, falls zwei eigene Teams in gleicher Liga |
| logo_file_id | → file null | Logo-Ebene 2 |
| min_participants | smallint null | Mindestteilnehmerzahl Training (global pro Team) |
| default_max_participants | smallint null | Vorgabe für neue Trainings |
| color | text | Akzentfarbe in der UI |
| archived_at | timestamptz null | |

Hierarchie-Rang (berechnet): `(league.branch, league.level, team.rank_in_league)` – kleinerer Wert = höheres Team.

### 🏢 `team_membership`
| Feld | Typ | Hinweis |
|---|---|---|
| team_id | → team | |
| person_id | → person | |
| role | enum `player`, `trainer`, `organizer` | eine Zeile pro Rolle |
| joined_at, left_at | date | |

Unique `(team_id, person_id, role)`. Ein Spieler kann so mehreren Teams angehören; ein Trainer mehrere Teams betreuen.

### 🏢 `cross_nomination` – Quernominierung (dauerhaft)
| Feld | Typ | Hinweis |
|---|---|---|
| host_team_id | → team | Team, zu dessen Trainings eingeladen wird |
| person_id | → person | |
| home_team_id | → team | Stammteam (für Prüfung „nur von unten nach oben“) |
| active | bool | pro Spieler aktivierbar |
| invited_by | → tenant_member | |

Prüfung beim Anlegen (Server): Rang(host) höher als Rang(home), Geschlechtsregel erfüllt (`men` ↔ `male`, `women` ↔ `female`, `mixed` ↔ alle). Gilt nur für Termine vom Typ `training` des Host-Teams.

### 🏢 `training_group` / `training_group_member`
`training_group`: `team_id`, `name` (z. B. „Zuspielertraining“). `training_group_member`: `training_group_id`, `person_id`.

---

## 4. Termine & Zu-/Absagen

### 🏢 `event_series` – Serientermin (Training)
`team_id`, `training_group_id` null, `title`, `rrule` (RFC 5545), `duration_min`, `location_name`, `address`, `hall_slot_id` null, `rsvp_deadline_offset_min` (z. B. 120 = 2 h vor Beginn), `max_participants` null, `until` date null. Ein Job erzeugt daraus konkrete `event`-Zeilen (rollierend 6 Monate).

### 🏢 `opponent` – wiederverwendbare Gegner
`name`, `logo_file_id` null. Logo-Ebene 3 (pro Gegner, am Spieltag ausgewählt bzw. neu hochgeladen).

### 🏢 `event`
| Feld | Typ | Hinweis |
|---|---|---|
| type | enum `training`, `match`, `extra`, `helper` | Training / Spieltag / Zusatztermin / Helferdienst |
| response_mode | enum `rsvp`, `info` | `info` = reine Informationsnotiz ohne Rückmeldung |
| title | text | |
| description | text null | |
| starts_at, ends_at | timestamptz | |
| meet_at | timestamptz null | Treffpunkt-Zeit |
| location_name, address | text null | Adresse → Google-Maps-Link |
| rsvp_deadline | timestamptz null | Frist |
| max_participants | smallint null | Teilnehmermaximum |
| series_id | → event_series null | |
| series_exception | bool | Einzeltermin wurde abweichend bearbeitet |
| training_group_id | → training_group null | |
| opponent_id | → opponent null | nur `match` |
| is_home | bool null | Heim-/Auswärtsspiel |
| scope | enum `team`, `tenant` | `tenant` = vom Admin für alle Teams |
| status | enum `scheduled`, `cancelled` | |
| created_by | → tenant_member | |

### 🏢 `event_team` – zu welchen Teams gehört der Termin
`event_id`, `team_id`. Bei `scope = tenant` werden alle (aktiven) Teams eingetragen; ein Termin kann damit auch mehreren ausgewählten Teams gehören.

### 🏢 `event_invitee` – eingeladene Personen (materialisiert)
`event_id`, `person_id`, `source` enum `team`, `group`, `cross_nomination`, `manual`.
Wird beim Erzeugen des Termins aus Teams/Gruppen/Quernominierungen befüllt und bei Kaderänderungen für zukünftige Termine nachgezogen. Basis für Statistik („war eingeladen“) und Startseite.

### 🏢 `event_position_quota` – Positions-Soll (Ausbaustufe)
`event_id` (oder `event_series_id`), `position`, `max_count`.

### 🏢 `event_response` – aktuelle Zu-/Absage
| Feld | Typ | Hinweis |
|---|---|---|
| event_id, person_id | | unique |
| status | enum `yes`, `no`, `maybe` | `maybe` optional (offene Frage 5) |
| as_position | enum `position` null | Position, für die zugesagt wurde (Positions-Soll) |
| comment | text null | Absagegrund |
| responded_by | → tenant_member | wer geklickt hat (Spieler selbst oder Elternteil) |
| responded_at | timestamptz | |

### 🏢 `event_response_log` – Historie „wer wann“
Append-only: `event_id`, `person_id`, `old_status`, `new_status`, `responded_by`, `created_at`, `after_deadline` bool.

**Kapazitätsprüfung:** Zusage läuft in einer Transaktion mit `SELECT … FROM event WHERE id = $1 FOR UPDATE`, zählt `yes`-Antworten (gesamt und je `as_position`) und lehnt bei vollem Limit ab.

---

## 5. Aufgaben & Helferdienste

### 🏢 `task` – Aufgabe zu einem Termin
`event_id`, `title` („Fahrer“, „Kuchen mitbringen“), `description`, `slots` smallint (benötigte Anzahl), `sort_order`.

### 🏢 `task_assignment`
`task_id`, `person_id`, `assigned_by`, `created_at`. Unique `(task_id, person_id)`.
Helferdienste = `event.type = helper` mit Aufgaben → Helfer-Statistik aus `task_assignment` (Anzahl übernommener Aufgaben pro Person und Zeitraum).

---

## 6. Umfragen

### 🏢 `poll`
`title`, `description`, `scope` enum `team`, `tenant`, `multiple_choice` bool, `anonymous` bool, `allow_free_text` bool, `closes_at` null, `created_by`.

### 🏢 `poll_team` – `poll_id`, `team_id` (bei `scope = tenant` alle Teams)
### 🏢 `poll_option` – `poll_id`, `label`, `sort_order`
### 🏢 `poll_vote` – `poll_id`, `option_id`, `person_id`, `voted_by`, `free_text` null

---

## 7. Infobereich

### 🏢 `notice`
`scope` enum `team`, `tenant`, `team_id` null, `title`, `body`, `visible_from`, `visible_until`, `created_by`, `pinned` bool.
Trainer: `scope = team` für eigene Teams; Admin: zusätzlich `tenant`.

---

## 8. Kasse

### 🏢 `cash_book`
`kind` enum `team`, `department`, `team_id` null (CHECK: `team` ⇒ team_id gesetzt; genau eine Abteilungskasse pro Mandant), `name`, `opening_balance_cents`.

### 🏢 `cash_entry`
| Feld | Typ | Hinweis |
|---|---|---|
| cash_book_id | → cash_book | |
| booked_on | date | |
| amount_cents | bigint | positiv = Einnahme, negativ = Ausgabe |
| category | text | z. B. „Mitgliedsbeitrag“, „Strafe“, „Material“ |
| description | text | |
| person_id | → person null | optional: betroffene Person |
| created_by | → tenant_member | |
| deleted_at | timestamptz null | Storno statt Löschen |

Admin-Gesamtübersicht = Aggregation über alle `cash_book` des Mandanten. Keine Zahlungsabwicklung.

---

## 9. Chat

### 🏢 `chat`
`kind` enum `team`, `custom`, `team_id` null (bei `team` automatisch angelegt), `name`, `mode` enum `flat`, `topics`, `created_by`.

### 🏢 `chat_member` – nur für `custom`-Chats (Teamchat leitet Mitglieder aus `team_membership` + Eltern ab)
`chat_id`, `tenant_member_id`, `role` enum `member`, `moderator`.

### 🏢 `chat_topic` – Unterkanäle (Telegram-Stil)
`chat_id`, `name`, `sort_order`, `archived_at`. Bei `mode = topics` gibt es ein Standard-Thema „Allgemein“.

### 🏢 `chat_message`
`chat_id`, `topic_id` null, `author_id` → tenant_member, `acting_for_person_id` null (Elternteil schreibt für Kind), `body` text, `reply_to_id` null, `edited_at`, `deleted_at`, `created_at`. Index `(chat_id, topic_id, created_at desc)`.

### 🏢 `chat_attachment` – `message_id`, `file_id`
### 🏢 `chat_read_state` – `chat_id`, `topic_id`, `tenant_member_id`, `last_read_message_id`

---

## 10. Hallen & Belegungsplan

### 🏢 `hall`
`name`, `address`, `courts` smallint (Anzahl Felder), `notes`.

### 🏢 `hall_slot` – verfügbare Zeiten der Halle
`hall_id`, `weekday` smallint (1–7), `start_time` time, `end_time` time, `valid_from`, `valid_until` date null (z. B. Schulferien ausschließen).

### 🏢 `hall_booking` – Team-Zuordnung
`hall_id`, `team_id`, `weekday`, `start_time`, `end_time`, `court` smallint null, `valid_from`, `valid_until`.
Konfliktwarnung: Abfrage auf Überschneidung gleicher `hall_id`/`weekday`/(`court`) mit überlappendem Zeit- und Gültigkeitsintervall. Kein harter Constraint, da laut Spezifikation nur gewarnt wird. Serientermine können eine `hall_booking` referenzieren.

Rechte: lesen = Trainer + Admin, schreiben = nur Admin.

---

## 11. Dateien, Benachrichtigungen, Kalender

### 🏢 `file`
`storage_key`, `mime_type`, `size_bytes`, `width`, `height`, `purpose` enum `avatar`, `team_logo`, `tenant_logo`, `opponent_logo`, `chat_attachment`, `uploaded_by`. Bilder werden serverseitig auf max. 512 px (Logos/Avatare) skaliert und als WebP gespeichert. (Das Mandantenlogo liegt ebenfalls hier, `tenant.logo_file_id` verweist darauf.)

### 🏢 `notification_preference`
`tenant_member_id`, `channel` enum `push`, `email`, `category` enum `new_event`, `event_changed`, `deadline_reminder`, `chat`, `poll`, `task`, `enabled` bool.

### 🏢 `calendar_feed`
`tenant_member_id`, `token_hash`, `created_at`, `revoked_at` – ICS-Abo-URL ohne Login.

### 🏢 `audit_log`
`actor_id`, `action`, `entity`, `entity_id`, `data` jsonb, `created_at` – für Rollenänderungen, Kassenbuchungen, Elternverknüpfungen.

---

## 12. ER-Übersicht (Kern)

```mermaid
erDiagram
  tenant ||--o{ tenant_member : hat
  account ||--o{ tenant_member : ist
  tenant_member ||--o{ person_account : handelt_fuer
  person ||--o{ person_account : wird_vertreten
  person ||--o{ team_membership : spielt_in
  team ||--o{ team_membership : hat
  league ||--o{ team : spielklasse
  league ||--o{ league : unterliga
  team ||--o{ cross_nomination : laedt_ein
  person ||--o{ cross_nomination : gast
  team ||--o{ training_group : hat
  event_series ||--o{ event : erzeugt
  event ||--o{ event_team : gilt_fuer
  team ||--o{ event_team : hat
  event ||--o{ event_invitee : laedt_ein
  event ||--o{ event_response : bekommt
  person ||--o{ event_response : gibt
  event ||--o{ task : hat
  task ||--o{ task_assignment : uebernommen
  opponent ||--o{ event : gegner
  team ||--o{ chat : teamchat
  chat ||--o{ chat_topic : themen
  chat ||--o{ chat_message : enthaelt
  cash_book ||--o{ cash_entry : buchungen
  hall ||--o{ hall_slot : zeiten
  hall ||--o{ hall_booking : belegt
  team ||--o{ hall_booking : nutzt
```

## 13. Wichtige Abfragen / Indizes

| Zweck | Index |
|---|---|
| Startseite (Infinite Scroll) | `event_invitee(tenant_id, person_id)`, `event(tenant_id, starts_at, id)` – Cursor `(starts_at, id) > ($cursor)` |
| Zusagen zählen | `event_response(event_id, status)` |
| Statistik je Team & Zeitraum | `event_team(team_id, event_id)` + `event(starts_at)`; Aggregation über `event_invitee` ⟕ `event_response` |
| Chat-Verlauf | `chat_message(chat_id, topic_id, created_at desc)` |
| Hallenkonflikt | `hall_booking(hall_id, weekday)` |
| Login | `auth_token(token_hash)`, `session(token_hash)` |
