# Claude Code Anweisung: Team-Organisation-App (Volleyball)

Diese Datei ist eine vollständige fachliche Spezifikation für den Bau einer eigenen Team-Organisation-Web-App (PWA) für eine Volleyball-Abteilung. Sie dient als direkte Arbeitsanweisung für Claude Code. Die App ist eine persönliche Alternative zu SpielerPlus und Spond und wird zunächst für die eigene Abteilung gebaut, ist aber von Anfang an mandantenfähig (siehe Technischer Rahmen).

> Inzwischen läuft sie als Plattform **VolleyOrga** (`app.volleyorga.de`) für viele Vereine: Jeder Verein nutzt sie für seine Volleyballabteilung. Ergänzungen und Entscheidungen seit dem ersten Entwurf stehen am Ende dieser Datei, Details in `umsetzungsplan.md` (Abschnitt 9).

## Sprachen

Die App wird von Anfang an zweisprachig gebaut: Deutsch und Englisch als Startsprachen. Alle UI-Texte müssen über ein Übersetzungssystem (z. B. i18n-Dateien, kein hartcodierter Text) eingebunden werden, sodass später weitere Sprachen ergänzt werden können. Der Nutzer wählt seine Sprache im Profil, Standard ist Deutsch.

## Technischer Rahmen

- Progressive Web App (PWA), eine Codebasis für alle Plattformen
- Push-Benachrichtigungen müssen unterstützt werden (auf iOS zuverlässig nur nach Installation auf dem Homescreen, das in der UI entsprechend kommunizieren)
- Die Software prüft selbst gegen, dass SSL korrekt aufgesetzt ist; sämtliche Verbindungen laufen ausschließlich über SSL/HTTPS, kein unverschlüsselter Zugriff möglich
- Die App wird von Anfang an multimandantenfähig (SaaS-Ebene) aufgebaut: Auf einer Installation/Domain können mehrere Abteilungen angelegt werden, jede mit eigenem Dach-Account, eigenen Teams, eigenen Mitgliedern und eigenen Daten. Die Abteilungen sind vollständig voneinander isoliert und verwalten sich komplett selbstständig; keine Abteilung sieht Daten einer anderen. Das Datenmodell muss die Abteilungs-/Mandanten-ID von Anfang an konsequent durch alle Tabellen und Abfragen durchziehen, auch wenn zunächst nur eine Abteilung genutzt wird.
- Mobile-first, da die meisten Nutzer vom Smartphone zugreifen

## Grundstruktur

- Ein Dach-Account bildet die gesamte Abteilung ab. Darunter existieren mehrere Teams.
- Ein Spielerprofil ist zentral und kann gleichzeitig mehreren Teams zugeordnet werden (Beispiel: ein Spieler ist gleichzeitig in der U16 männlich, der U18 männlich und der niedrigsten Herrenmannschaft aktiv).
- Die App wird zunächst für die eigene Volleyball-Abteilung gebaut, aber von Anfang an mandantenfähig, sodass später weitere Abteilungen angelegt werden können, die untereinander nichts voneinander sehen.

## Anmeldung / Login

Die Anmeldung erfolgt ausschließlich per Magic Link: Der Nutzer gibt seine E-Mail-Adresse ein und erhält einen Einmal-Link per Mail, über den er sich anmeldet. Es gibt kein klassisches Passwort. Gründe: besserer Datenschutz und geringeres Risiko durch Passwort-Leaks/Hacking. Nach einmaliger Anmeldung per Magic Link bleibt der Nutzer 3 Monate am Stück angemeldet (lange Session statt erneutem Link-Login bei jedem Besuch).

## Rollen und Rechte

- **Admin**: sieht und verwaltet alles auf Abteilungsebene, über alle Teams hinweg.
- **Trainer**: pro Team zuweisbar; ein Trainer kann mehrere Teams gleichzeitig verwalten.
- **Betreuer/Organisator**: übernimmt organisatorische Aufgaben innerhalb eines Teams, ohne Zugriff auf sensible Auswertungen wie Zu-/Absage-Quoten.
- **Spieler**: normaler Nutzer-Account mit Zugriff auf die eigenen Daten und Team-Funktionen.
- **Eltern-Account** (für nicht volljährige Spieler): ein Elternteil kann sich über das Profil des Kindes schalten und stellvertretend entscheiden. Das Elternkonto ist voll funktionsfähig wie ein normaler Account (Zu-/Absagen, Aufgaben annehmen, Chat nutzen) und hat zusätzlich die alleinige Entscheidungsbefugnis über das Profil des Kindes.

## Navigation und Startseite

- Keine untere Tab-Leiste (wie bei SpielerPlus), stattdessen ein Ausklappmenü oben rechts, damit mehr Bildschirmfläche für Inhalte bleibt.
- Die Startseite zeigt direkt die Zu-/Absage-Liste der anstehenden Termine.
- Die Liste lädt per echtem Infinite Scroll automatisch weitere Termine nach, sobald man nach unten scrollt (kein "Weiter"-Button).

**Mitlaufender Speichern-Button:** In jeder Maske mit einem Speichern-Button (z. B. bei der Spielerliste) bleibt der Button beim Scrollen immer sichtbar.

## Termine

**Terminarten:**

- Serientermine für Training
- Normale Spieltage
- Zusatztermine, z. B. Vereinssitzungen oder Besprechungen

Jede Terminart ist optisch unterschieden, z. B. durch Farbe oder ein Icon (Pokal-Icon für Wettkampf/Spieltag, kein Icon für Training).

**Regeln:**

- Der Admin kann einen Termin (z. B. eine Vereinssitzung) gleichzeitig für alle Teams ansetzen.
- Zu-/Absage ist grundsätzlich bei jedem Termin möglich.
- Bei Zusatzterminen kann die erstellende Person wählen zwischen: (a) Abstimmung mit Zu-/Absage, oder (b) reine Informationsnotiz ohne Rückmeldungspflicht (z. B. bei einer Hauptversammlung des Hauptvereins).

**Spieltage – Gegner-Logos:**

- Pro Spieltag kann ein Logo des gegnerischen Vereins hochgeladen werden (Beispiel: Spieltag SSV Ulm gegen Bahn Walze Herren 1).
- Pro eigenem Team kann ein eigenes Team-Logo hinterlegt werden.
- Der Administrator kann zusätzlich ein globales Logo für die gesamte Abteilung hochladen.
- Es gibt also drei Logo-Ebenen: global (Abteilung), pro Team, und pro Gegner (je Spieltag).

## Wegbeschreibung (Google Maps)

Für den Terminort reicht ein einfacher Button "Wegbeschreibung starten", der über einen simplen Link auf Google Maps verweist:

```
https://www.google.com/maps/dir/?api=1&destination=<Adresse>
```

Es ist **kein** Places- oder Geocoding-API-Aufruf nötig (würde einen API-Key und laufende Kosten erfordern). Die eingegebene Adresse wird nicht vorab geprüft oder abgeglichen, der Link wird direkt aus der Adresse gebaut.

## Statistik (Zu-/Absagen)

- Erfasst wird, wer wann zu- oder abgesagt hat.
- **Sichtbarkeit:** Ein Spieler sieht nur seine eigene Quote. Admin und Trainer sehen die Quoten aller Spieler (Entscheidungsgrundlage z. B. dafür, wer spielen darf). Betreuer/Helfer sehen die Quoten nicht, um keinen Wettbewerb untereinander zu erzeugen.
- **Grafische Aufbereitung:** Die Statistik wird zusätzlich als Balkendiagramm dargestellt, und zwar als Vergleichsdiagramm über alle Spieler eines Teams hinweg. Der Zeitraum (z. B. letzte 4 Wochen, Saison, frei wählbar) ist vom Trainer einstellbar, damit er auf einen Blick sieht, wer wie oft anwesend war.

## Taktik

Eine Aufstellungs- oder Taktikboard-Funktion ist **nicht** gewünscht und wird bewusst nicht gebaut.

## Trainings

- **Trainingsgruppen:** Innerhalb eines Teams können eigene Trainingsgruppen definiert werden (z. B. ein Zuspielertraining), für die eigene Trainings angesetzt werden können.
- **Fristen:** Wie bei SpielerPlus hat jeder Termin eine Frist, bis zu der Spieler zu- oder absagen können.
- **Teilnehmermaximum:** Trainer/Admin können pro Training ein Maximum festlegen (z. B. 12 Spieler). Ist es erreicht, schließt die App die Zusage automatisch, sodass quernominierte Spieler zum Auffüllen zusagen können.
- **Positionsbasierte Nominierung (Ausbaustufe):** Der Trainer legt in den Grundeinstellungen pro Position ein Soll fest (z. B. bei 12 Spielern: 2 Zuspieler, 2 Diagonalangreifer, 4 Mittelblocker, 2 Libero). Die Zusage stoppt automatisch, sobald eine Position voll belegt ist, auch wenn das Gesamtlimit noch nicht erreicht ist.
- **Mindestteilnehmerzahl:** Pro Team wird global ein Minimum festgelegt, damit ein Training stattfindet. Solange es nicht erreicht ist, wird der Termin farblich hervorgehoben (z. B. gelb).
- **Training kommt nicht zustande:** Kann das Minimum rechnerisch nicht mehr erreicht werden (auch dann nicht, wenn alle noch offenen Spieler zusagen), gibt das System aus, dass das Training nicht zustande kommt. Ändern Spieler danach ihre Meinung und das Minimum ist wieder erreichbar, wird der Termin wieder grün angezeigt. Der Status wird bei jeder Zu- oder Absage neu berechnet.

## Quernominierung

- Trainer/Admin können Spieler aus anderen Teams (z. B. aus Herren 2 oder der U18) dauerhaft zu Trainings eines anderen Teams (z. B. Herren 1) einladen.
- Die Quernominierung ist pro Spieler aktivierbar. Der Trainer hat dafür einen Button, mit dem er Spieler aus unteren Teams einladen kann.
- **Mannschaftshierarchie:** Teams stehen in einer Hierarchie. Ein Spieler aus einem höheren Team kann nicht in einem niedrigeren Team mitspielen, Quernominierung geht nur von unten nach oben.
- **Geschlechtsart:** Beim Team wird definiert, ob es ein Herren-, Damen- oder Mixed-Team ist. Herrenteams können nur Herren einladen, Damenteams nur Damen, Mixed-Teams beide Geschlechter.
- **Liga-Baum:** Für jedes Team wird die Liga aus dem Liga-Baum des Volleyballs ausgewählt (von der Bundesliga abwärts bis zur untersten Spielklasse). Der Liga-Baum ist vom Admin pflegbar, da die Ligastruktur je Landesverband abweicht. Aus der Liga ergibt sich die Rangfolge der Teams für die Hierarchie.

## Hallen und Trainingszeiten

- Es gibt eine Hallenübersicht. Hallen werden in der App mit ihren verfügbaren Zeiten angelegt. Der Bereich ist für Trainer einsehbar und nur für Admins bearbeitbar. *(Entscheidung: einsehbar für alle Mitglieder, siehe unten.)*
- Ein Team kann einer Halle und einer Zeit zugeordnet werden. Belegen zwei Teams dieselbe Halle zur selben Zeit, warnt die App.

## Infobereich

Im oberen Bereich der Spieleransicht gibt es einen Infobereich. Der Trainer kann dort Notizen für einen wählbaren Zeitraum einblenden, der Admin über alle Teams hinweg.

## Helferdienste

Es gibt eine zusätzliche Terminart für Helferdienste/Helfertermine (ähnliche Kategorie wie Zusatztermine). Dort können Aufgaben definiert werden, für die Personen zusagen können. Eine eigene Statistik erfasst, wer sich bei eigenen Veranstaltungen bereit erklärt hat, Aufgaben zu übernehmen.

## Kasse

- Jedes Team hat eine eigene Teamkasse mit manuellen Ein- und Ausgabenbuchungen.
- Zusätzlich gibt es für den Admin eine Gesamtübersicht über alle Teamkassen.
- Zusätzlich gibt es eine eigene, gemeinsame Abteilungskasse für abteilungsweite Ausgaben (unabhängig von den einzelnen Teamkassen).
- Es wird **keine echte Zahlungsabwicklung** integriert (keine Zahlungsanbieter-Anbindung). Die Kasse ist eine rein informative Buchführungs-Statistik.

## Chat

- Standardmäßig gibt es einen Chat pro Team.
- Der Administrator kann zusätzlich eigene Chats mit frei ausgewählten Personen erstellen (z. B. ein Chat nur für die fünfköpfige Abteilungsleitung).
- Pro Chat ist wählbar, ob es ein normaler Gruppenchat ist, oder ob er wie bei Telegram in Themen/Unterkanäle aufgeteilt wird.

## Umfragen

- Umfragen sind pro Team möglich.
- Der Administrator kann zusätzlich Umfragen teamübergreifend an alle Teams gleichzeitig ausspielen.

## Aufgaben

Zu Terminen können Aufgaben angelegt werden (z. B. wer fährt, wer bringt was mit).

## Spielerprofil

Jedes Spielerprofil enthält folgende Angaben:

- Name
- Trikotnummer
- Geburtstag
- Telefonnummer
- E-Mail-Adresse
- Postadresse
- Position auf dem Feld (soll dem Trainer eingeblendet werden können, damit er sieht, wie viele Spieler welcher Position z. B. im Training anwesend sind)
- Profilbild (Upload eines kleinen Bildes)
- Körpergröße (volleyballtypische Kennzahl, z. B. relevant für die Positionswahl)
- Reichhöhe / Sprunghöhe (volleyballtypische Vergleichskennzahl unter Spielern)

**Schiedsrichter-Lizenz:** Im Spielerprofil kann zusätzlich eine Schiedsrichter-Lizenz mit auswählbarem Ablaufdatum hinterlegt werden. Dem Trainer wird sie (inkl. Ablaufdatum) eingeblendet.

## Einordnung gegenüber SpielerPlus und Spond

Die App übernimmt die Funktionen beider Systeme als Grundlage (Termine, Aufgaben, Chat, Zahlungs-/Kassenlogik, Rollenmodell, Statistiken), löst aber bekannte Schwachstellen beider Systeme auf:

- Bessere Übersicht durch Ausklappmenü statt Tab-Leiste und direkten Fokus der Startseite auf Zu-/Absagen.
- Keine Werbung, kein künstliches Mitgliederlimit, kein kostenpflichtiger Kalender-Sync.
- Klare Trennung der Sichtbarkeiten bei Statistiken, um Wettbewerbsdruck bei Helfern zu vermeiden.
- Volleyballspezifische Zusatzfelder (Körpergröße, Reichhöhe), die in keinem der beiden Referenzsysteme Standard sind.

## Nicht enthaltene Funktionen (bewusst ausgeschlossen)

- Aufstellungs-/Taktikboard
- Echte Zahlungsabwicklung/Zahlungsanbieter-Integration in der Kasse

## Status

**Vorgehen für Claude Code:** Lies die gesamte Spezifikation. Schlage zuerst einen technischen Umsetzungsplan und das Datenmodell vor (mit durchgängiger Mandanten-ID), bevor die Implementierung beginnt. Empfohlene Reihenfolge: (1) Login per Magic Link, Mandanten, Teams, Rollen; (2) Termine, Zu-/Absage, Startseite; (3) Trainings-Logik (Limit, Minimum, Quernominierung, Hierarchie, Hallen); (4) Statistik, Helferdienste, Aufgaben; (5) Chat, Umfragen, Kasse.

> Stand 10/2026: Plan und Datenmodell sind in `umsetzungsplan.md` und `datenmodell.md` festgehalten; alle Punkte dieser Spezifikation sind umgesetzt.

**Hinweise zum Schutz des Codes:** Repository privat halten, die gesamte Logik (Rechte, Statistik, Mandantentrennung) serverseitig umsetzen und eine Liste der verwendeten Bibliotheken samt Lizenzen führen.

> Rechte, Statistik und Mandantentrennung laufen ausschließlich serverseitig. Die Bibliotheksliste steht in `lizenzen.md` (neu erzeugen mit `npm run licenses` im Ordner `frontend/`). Die Sichtbarkeit des GitHub-Repositorys stellt der Inhaber unter *Settings → General → Danger Zone → Change visibility* ein.

**Ideen für später** (noch nicht beauftragt, nur nach Rücksprache bauen) – mit Stand der Umsetzung:

- Kalender-Abo (iCal) für alle Termine – ✅ umgesetzt
- Erinnerung an offene Zusagen kurz vor Fristende – ✅ umgesetzt (24 Stunden vor der Frist)
- Warteliste mit automatischem Nachrücken – ❌ bewusst nicht (Entscheidung: kein Wartelisten-Mechanismus, frei werdende Plätze sind sofort wieder buchbar)
- Tatsächliche Anwesenheit im Training abhaken – ✅ umgesetzt
- Fahrgemeinschaften bei Spieltagen – ◐ über Aufgaben (z. B. „Fahrer“ mit Plätzen), keine eigene Funktion
- Warnung vor ablaufender Schiedsrichter-Lizenz – ◐ Hinweis im Kader 60 Tage vor Ablauf, keine Nachricht
- Spieltagseinteilung (Schreiber, Schiedsrichter, Hallenaufsicht) – ✅ über Aufgaben und Aufgaben-Vorlagen
- Spielergebnisse und kurze Spielberichte – offen
- Hallensperrung mit automatischer Absage betroffener Trainings – ✅ umgesetzt (wahlweise absagen oder löschen)
- Saisonwechsel mit Übernahme von Teams und Spielern – offen
- Dokumentenablage (z. B. Elterneinverständnis) – ✅ umgesetzt
- Datenschutz: Export und Löschung der eigenen Daten – ✅ umgesetzt

Die App-Namensfrage ist noch offen (die Namen "VolleyPlan" und "VolleyTeam" sind bereits belegt). *(Entschieden: VolleyOrga.)*

## Ergänzungen und Entscheidungen seit dem ersten Entwurf

Kurzfassung; Einzelheiten in `umsetzungsplan.md`, Abschnitt 9.

- **Plattform:** Viele Vereine teilen sich eine Installation; jeder Verein ist ein eigener Mandant (seine Volleyballabteilung). Vereine registrieren sich selbst, der Betreiber gibt sie frei. Adresse eines Vereins: `app.volleyorga.de/<kürzel>`. Ein Superadmin (Betreiber) sieht alle Konten und kann sie löschen; Konten, die über 2 Jahre nicht genutzt wurden, bekommen eine Löschankündigung (14 Tage).
- **Rechtliches:** Impressum, Datenschutzerklärung und AGB von jeder Seite erreichbar; Betreiberangaben pflegt der Superadmin. Werbe-E-Mails nur mit ausdrücklicher Einwilligung im Profil.
- **Spielplan-Import:** Spieltage je Mannschaft aus .ics (iCalendar) oder CSV (z. B. SAMS-Export) importieren, mit Vorschau und Auswahl; Gegner werden zugeordnet oder angelegt; erneuter Import aktualisiert statt doppelt anzulegen.
- **Hallen:** Einsehbar für alle Mitglieder (nicht nur Trainer), bearbeitbar nur für Admins. Hallen mit Hallenteilen (z. B. 2 von 3), Ansprechpartner, Adresse (Straße, Nr., PLZ, Ort) und Trainingszeiten wie Öffnungszeiten; sie gelten nur für Trainings.
- **Idealkader und fehlende Positionen:** Jedes Team legt seinen Idealkader je Position fest; pro Training lässt sich „Gäste nur für fehlende Positionen“ einschalten. In der Rückmeldeliste steht bei jedem Spieler seine Position als Kürzel.
- **Training kommt nicht zustande:** Gezählt wird, wer höchstens noch kommen kann (Zusagen, „Vielleicht“ und bis zur Frist Unbeantwortete). Gelb = Minimum noch erreichbar, rot = „Training findet nicht statt“, grün = Minimum erreicht. Nur Anzeige, keine automatische Absage; Gäste werden nicht mitgezählt.
- **Für mehrere Termine antworten:** Daueranworten für eine Terminart oder Trainingsreihe, optional nur an bestimmten Wochentagen und bis zu einem Enddatum (z. B. Saisonende); einzelne Termine lassen sich danach anders beantworten.
- **Antworten für Spieler eintragen:** Trainer, Betreuer und Admins setzen Zu-/Absagen direkt in der Rückmeldeliste, z. B. nach einem Gespräch.
- **Nicht reagiert und Anwesenheit:** Wer bis zur Frist nicht reagiert hat, wird markiert und in der Statistik ausgewertet; Anwesenheit im Training in Prozent; Trainer und Admins können bei vergangenen Terminen Antworten und Anwesenheit nachtragen.
- **Termine absagen, löschen, Serien kürzen:** Jede Terminart lässt sich absagen (mit Rückfrage) und löschen – auch einzelne Serientermine. Wird eine Serie gekürzt oder beendet, fragt die App, ob die wegfallenden Termine abgesagt oder gelöscht werden.
- **Aufgaben:** Anzahl Personen optional; Aufgaben-Vorlagen (z. B. „Spieltag Damen 1“) für Termine und Trainingsreihen; Aufgaben lassen sich beim Anlegen eines Spieltags, Turniertags oder Helferdiensts direkt von Hand eintragen.
- **Umfragen und Infos benachrichtigen:** Push-Nachricht und wahlweise E-Mail; Erinnerung an offene Umfragen. Infos auf der Startseite brauchen ein Verfallsdatum.
- **Dokumentenablage:** Für den ganzen Verein (z. B. Vereinsmeldung) und je Mannschaft (z. B. Aufbauanleitung nur für Herren 1), mit Ordnern.
- **Konto löschen:** Jede Person kann ihr Konto selbst löschen (Bestätigung durch Eintippen der E-Mail-Adresse).
- **Hallensperrung:** Admins sperren eine Halle für einen Zeitraum; die Termine dort werden – nach Auswahl – abgesagt oder gelöscht, Trainingsreihen lassen die Tage aus; eine Nachricht an alle Betroffenen.
- **Bedienung:** App installieren (Banner mit Anleitung), Dunkelmodus (folgt dem Gerät, im Profil umschaltbar), Menü als Kacheln, Gegner-Logo direkt im Spieltag hochladen.
