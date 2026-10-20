# Claude Code Anweisung: Team-Organisation-App (Volleyball)

Diese Datei ist eine vollständige fachliche Spezifikation für den Bau einer eigenen Team-Organisation-Web-App (PWA) für eine Volleyball-Abteilung. Sie dient als direkte Arbeitsanweisung für Claude Code. Die App ist eine Alternative zu SpielerPlus und Spond. Sie läuft als Plattform (VolleyOrga, `app.volleyorga.de`) für viele Vereine: Jeder Verein nutzt sie für seine Volleyballabteilung – nicht für einen Verein mit mehreren Sportabteilungen.

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
- Viele Vereine teilen sich eine Installation; jeder Verein ist ein eigener Mandant (seine Volleyballabteilung). Vereine registrieren sich selbst, der Betreiber der Plattform gibt sie frei. Die Adresse eines Vereins ist `app.volleyorga.de/<kürzel>`.

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

**Spielplan-Import (nachträglich ergänzt):**

- Spieltage können je Mannschaft aus einer Kalenderdatei importiert werden: .ics (iCalendar) und CSV (z. B. Spielplan-Export des Verbands).
- Vor dem Import zeigt die App eine Vorschau der erkannten Spiele; einzelne Spiele lassen sich abwählen.
- Gegner werden automatisch zugeordnet oder angelegt; ein erneuter Import der aktualisierten Datei aktualisiert bestehende Spiele, statt sie doppelt anzulegen.

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

Quernominierung für Trainingsgäste: Trainer/Admin können Spieler aus anderen Teams (z. B. aus Herren 2 oder der U18) dauerhaft zu Trainings eines anderen Teams (z. B. Herren 1) einladen. Pro Training kann ein Teilnehmermaximum definiert werden (z. B. 12 Spieler); ist das Limit erreicht, schließt die App die Zusage automatisch, sodass quernominierte Spieler zum Auffüllen zusagen können. Als Ausbaustufe: positionsbasierte Nominierung, bei der der Trainer pro Position ein Soll festlegt (z. B. bei 12 Spielern: 2 Zuspieler, 2 Diagonalangreifer, 4 Mittelblocker, 2 Libero) – die Zusage stoppt automatisch, sobald eine Position voll belegt ist, auch wenn das Gesamtlimit noch nicht erreicht ist.

Trainingsgruppen: Innerhalb eines Teams können eigene Trainingsgruppen definiert werden (z. B. ein Zuspielertraining), für die eigene Trainings angesetzt werden können.

Mindestteilnehmerzahl: Für Trainings kann ein Minimum an Spielern definiert werden, damit das Training stattfindet. Das Minimum wird global pro Team festgelegt. Solange das Minimum nicht erreicht ist, wird der Termin farblich hervorgehoben (z. B. gelb).

Schiedsrichter-Lizenz: Im Spielerprofil kann eine Schiedsrichter-Lizenz hinterlegt werden, mit auswählbarem Ablaufdatum. Dem Trainer wird die Lizenz (inkl. Ablaufdatum) eingeblendet.

Quernominierung aktivieren: Die Quernominierung ist pro Spieler aktivierbar. Der Trainer hat dafür einen Button, mit dem er Spieler aus unteren Teams zu Trainings seines Teams einladen kann.

Idealkader und fehlende Positionen: Jedes Team legt fest, wie es sich seinen idealen Kader vorstellt – wie viele Spieler je Position (z. B. Mixed oft ohne Libero und Diagonal, dafür mehr von den anderen Positionen). Pro Training kann der Trainer „Gäste nur für fehlende Positionen“ einschalten: Dann können sich nur Quernominierte eintragen, deren Position laut Positions-Soll des Trainings (sonst laut Idealkader) noch gesucht wird.

Mannschaftshierarchie: Die Teams einer Abteilung stehen in einer Hierarchie. Ein Spieler aus einem höheren Team kann nicht in einem niedrigeren Team mitspielen; Quernominierung ist nur von unten nach oben möglich.

Liga-Baum: Für jedes Team wird die Liga aus dem Liga-Baum des Volleyballs ausgewählt (Ligaebenen von der Bundesliga abwärts bis zur untersten Spielklasse). Der Liga-Baum soll vom Admin pflegbar sein, da die Ligastruktur je Landesverband abweicht. Aus der gewählten Liga ergibt sich die Rangfolge der Teams für die Hierarchie.

Geschlechtsart des Teams: Beim Team wird definiert, ob es ein Herren-, Damen- oder Mixed-Team ist. Herrenteams können per Quernominierung nur Herren einladen, Damenteams nur Damen. Mixed-Teams können Spieler beider Geschlechter einladen.

Hallen- und Trainingszeitenplan: Es gibt einen Plan für die verfügbaren Hallen und Trainingszeiten der Abteilung. Ein Team kann einer Halle (ganz oder einzelnen Hallenteilen) und einer Zeit zugeordnet werden. Belegen zwei Teams dieselbe Halle zur selben Zeit, warnt die App.

Hallenübersicht: Es gibt eine Übersicht aller Hallen. Hallen werden in der App mit den jeweiligen verfügbaren Zeiten und Hallenteilen angelegt (z. B. nur 2 von 3 Teilen, wenn den dritten Teil eine andere Sportart nutzt), dazu ein Ansprechpartner je Halle. Der Bereich ist für alle Mitglieder einsehbar und nur für Admins bearbeitbar. Die Adresse einer Halle besteht aus Straße, Hausnummer, PLZ und Ort (alle optional). Die verfügbaren Trainingszeiten werden direkt in der Halle wie Öffnungszeiten gepflegt (Montag 18:00–20:00, Dienstag …); sie gelten nur für Trainings, Spieltage und andere Termine sind davon unabhängig.

Infobereich für Spieler: Im oberen Bereich der Spieleransicht gibt es einen Infobereich. Der Trainer kann dort Notizen für einen wählbaren Zeitraum einblenden. Der Admin kann das über alle Teams hinweg.

Fristen für Zu- und Absage: Wie bei SpielerPlus hat jeder Termin eine Frist, bis zu der Spieler zu- oder absagen können.

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

- Zusätzliche Terminart für Helferdienste/Helfertermine (ähnliche Kategorie wie Zusatztermine): Hier können Aufgaben definiert werden, für die Personen zusagen können. Eigene Statistik erfasst, wer sich bei eigenen Veranstaltungen/Helferdiensten bereit erklärt hat, Aufgaben zu übernehmen.
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

Dies ist der aktuelle Stand des Brainstormings und kann in weiteren Iterationen ergänzt werden (z. B. Fahrgemeinschaften, Spielberichte im Detail, konkretes Datenmodell). Claude Code soll auf Basis dieser Spezifikation einen technischen Umsetzungsplan sowie die Datenmodellierung vorschlagen, bevor die Implementierung beginnt.

Positionen in der Rückmeldeliste: Bei den Spielern im Termin steht ihre Position als Kürzel, damit der Trainer schnell sieht, wer auf welcher Position kommt.

Konto löschen: Jede Person kann ihr ganzes Konto selbst löschen – abgesichert durch erneutes Eintippen der E-Mail-Adresse. Plattform-Admins können gesperrte Vereine endgültig löschen.

Für mehrere Termine antworten: Ein Spieler kann für eine Terminart oder eine Trainingsreihe auf einmal zu- oder absagen, auch nur für bestimmte Wochentage („mittwochs kann ich immer, freitags nie“). Danach lassen sich einzelne Termine wieder anders beantworten.

Nicht reagiert und Anwesenheit: Wer zu einem Termin bis zur Frist nicht reagiert hat, wird extra markiert und in der Statistik ausgewertet. Die Statistik zeigt außerdem die Anwesenheit im Training in Prozent; dazu können Trainer und Admins bei vergangenen Terminen Antworten und Anwesenheit nachtragen.

Dokumentenablage: Es gibt eine Ablage für Dokumente, übergeordnet für den ganzen Verein (z. B. die Vereinsmeldung als PDF, für alle sichtbar) und je Mannschaft (z. B. die Aufbauanleitung für den Spieltag nur in Herren 1). Dokumente lassen sich in Ordner sortieren.

Superadmin und Kontenpflege: Der Betreiber hat eine Superadmin-Oberfläche mit allen Konten und kann Konten bei Bedarf löschen. Ein Konto, das insgesamt länger als 2 Jahre nicht genutzt wurde, bekommt eine E-Mail, dass es in 14 Tagen gelöscht wird; wer sich vorher anmeldet, behält es.

Rechtliches: Impressum, Datenschutzerklärung und AGB sind von jeder Seite erreichbar; die Betreiberangaben pflegt der Superadmin. Die AGB regeln auch Werbung: Werbe-E-Mails gibt es nur mit ausdrücklicher Einwilligung im Profil.
