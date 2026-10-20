import { Link } from 'react-router';
import type { LegalDetails } from '../lib/api';
import { OperatorAddress, operatorName } from './Operator';

/** Allgemeine Geschäftsbedingungen incl. Auftragsverarbeitung (Art. 28 DSGVO). German only. */
export function Terms({ legal }: { legal: LegalDetails }) {
  const operator = operatorName(legal);
  return (
    <>
      <h2>§ 1 Geltungsbereich und Anbieter</h2>
      <p>Diese Bedingungen gelten für die Nutzung der Plattform VolleyOrga (app.volleyorga.de). Anbieter ist:</p>
      <OperatorAddress legal={legal} />
      <p>
        Nutzer sind Vereine, die ihre Volleyballabteilung auf der Plattform anmelden („Verein“), sowie Personen, die ein Konto haben
        („Mitglied“), z. B. Spieler, Eltern, Trainer, Betreuer und Admins.
      </p>

      <h2>§ 2 Leistungen</h2>
      <p>
        VolleyOrga hilft Vereinen, ihre Volleyballabteilung zu organisieren: Mannschaften, Termine mit Zu- und Absagen, Benachrichtigungen,
        Statistik, Aufgaben, Umfragen, Chat, Kasse, Hallen und Dokumente. Die Nutzung ist derzeit kostenlos. Ein Anspruch auf bestimmte
        Funktionen besteht nicht; Funktionen können weiterentwickelt, geändert oder eingestellt werden. Sollten kostenpflichtige
        Leistungen eingeführt werden, werden sie vorher angekündigt und nur nach gesonderter Vereinbarung berechnet.
      </p>

      <h2>§ 3 Anmeldung und Konten</h2>
      <ul>
        <li>Einen Verein anmelden darf nur, wer dazu vom Verein berechtigt ist. Der Anbieter prüft die Anmeldung und kann sie ohne Angabe von Gründen ablehnen.</li>
        <li>Mitglieder treten über Einladungslinks des Vereins bei. Die Anmeldung erfolgt ohne Passwort über einen Link per E-Mail; das E-Mail-Postfach ist daher geheim zu halten.</li>
        <li>Für Minderjährige legen die Eltern bzw. der Verein das Profil an; die Eltern bestimmen, was das Kind selbst darf.</li>
        <li>Die Admins eines Vereins verwalten den Verein auf der Plattform (Mitglieder, Rechte, Inhalte) und sind dafür gegenüber dem Verein verantwortlich.</li>
      </ul>

      <h2>§ 4 Pflichten der Nutzer</h2>
      <p>Nutzer verpflichten sich, die Plattform nur für die Organisation des Vereinssports zu nutzen und insbesondere</p>
      <ul>
        <li>keine rechtswidrigen, beleidigenden, diskriminierenden oder jugendgefährdenden Inhalte einzustellen,</li>
        <li>nur Inhalte (Fotos, Dokumente, Daten Dritter) hochzuladen, zu denen sie berechtigt sind,</li>
        <li>die Plattform nicht für Werbung, Kettenbriefe oder Massennachrichten an Mitglieder ohne deren Einwilligung zu nutzen,</li>
        <li>keine Schadsoftware hochzuladen und die Plattform nicht technisch zu stören oder auszuspähen.</li>
      </ul>

      <h2>§ 5 Inhalte</h2>
      <p>
        Die Rechte an Inhalten bleiben bei den Nutzern bzw. dem Verein. Der Anbieter erhält nur die Rechte, die er zum Betrieb der
        Plattform braucht (Speichern, Anzeigen für die Berechtigten, technische Verarbeitung). Der Anbieter darf Inhalte, die gegen diese
        Bedingungen oder geltendes Recht verstoßen, entfernen.
      </p>

      <h2>§ 6 Verfügbarkeit</h2>
      <p>
        Der Anbieter bemüht sich um einen störungsfreien Betrieb, schuldet aber keine bestimmte Verfügbarkeit. Wartungen und
        Aktualisierungen können zu kurzen Unterbrechungen führen. Vereine sollten für sie wichtige Daten (z. B. Kassenbuch über den
        CSV-Export) zusätzlich selbst sichern.
      </p>

      <h2>§ 7 Datenschutz und Auftragsverarbeitung</h2>
      <p>
        Für personenbezogene Daten im Verein ist der Verein Verantwortlicher; der Anbieter verarbeitet sie in seinem Auftrag. Dafür gilt
        die <a className="text-brand-700 underline" href="#auftragsverarbeitung">Anlage Auftragsverarbeitung</a>, die mit der Anmeldung
        des Vereins vereinbart wird. Im Übrigen gilt die <Link className="text-brand-700 underline" to="/datenschutz">Datenschutzerklärung</Link>.
      </p>

      <h2>§ 8 Werbung und E-Mails</h2>
      <ul>
        <li>
          <strong>Service-E-Mails</strong> gehören zur Nutzung und sind keine Werbung: Anmeldelinks, Einladungen, Benachrichtigungen nach den
          Einstellungen im Profil, Hinweise zum Konto (z. B. vor einer Löschung wegen Inaktivität) und wichtige Änderungen dieser Bedingungen.
        </li>
        <li>
          <strong>Werbe-E-Mails</strong> (Neuigkeiten, Angebote und Aktionen rund um VolleyOrga, auch von Partnern) verschickt der Anbieter nur an
          Mitglieder, die im Profil ausdrücklich eingewilligt haben. Die Einwilligung ist freiwillig, keine Voraussetzung für die Nutzung und
          jederzeit im Profil oder per E-Mail an den Anbieter widerrufbar; jede Werbe-E-Mail enthält einen Hinweis zum Abbestellen.
        </li>
        <li>E-Mail-Adressen und andere Daten werden nicht an Dritte zu Werbezwecken verkauft oder weitergegeben.</li>
        <li>
          Die Plattform zeigt derzeit keine Werbung Dritter. Sollte künftig Werbung eingeblendet werden, wird sie deutlich als „Anzeige“
          gekennzeichnet; personalisierte Werbung oder Tracking erfolgt nicht ohne vorherige Einwilligung. Hinweise auf eigene Angebote des
          Anbieters innerhalb der Plattform bleiben vorbehalten.
        </li>
        <li>Vereine dürfen über die Plattform (Infos, Chat, Push) nicht für Dritte werben, es sei denn, es handelt sich um Vereinsbelange (z. B. Sponsoren des Vereins, Vereinsfeste).</li>
      </ul>

      <h2>§ 9 Haftung</h2>
      <p>
        Der Anbieter haftet unbeschränkt bei Vorsatz und grober Fahrlässigkeit, bei Verletzung von Leben, Körper oder Gesundheit sowie nach
        dem Produkthaftungsgesetz. Bei leichter Fahrlässigkeit haftet er nur für die Verletzung wesentlicher Pflichten, deren Erfüllung die
        Nutzung erst ermöglicht und auf die die Nutzer vertrauen dürfen, und begrenzt auf den vorhersehbaren, typischen Schaden. Da die
        Plattform unentgeltlich bereitgestellt wird, haftet der Anbieter im Übrigen nur für Vorsatz und grobe Fahrlässigkeit
        (§§ 521, 599 BGB entsprechend). Für Inhalte der Vereine und Mitglieder haftet der Anbieter nicht.
      </p>

      <h2>§ 10 Laufzeit, Kündigung und Löschung</h2>
      <ul>
        <li>Mitglieder können ihr Konto jederzeit im Profil löschen. Vereine können die Löschung ihres Vereins jederzeit beim Anbieter verlangen.</li>
        <li>Konten, die länger als 2 Jahre nicht genutzt wurden, werden nach vorheriger Ankündigung per E-Mail mit 14 Tagen Frist gelöscht.</li>
        <li>Der Anbieter kann die Bereitstellung mit einer Frist von 3 Monaten beenden; Vereine erhalten dann Gelegenheit, ihre Daten zu exportieren.</li>
        <li>Bei schweren Verstößen gegen diese Bedingungen kann der Anbieter Konten oder Vereine sofort sperren und nach Anhörung löschen.</li>
      </ul>

      <h2>§ 11 Änderungen</h2>
      <p>
        Der Anbieter kann diese Bedingungen mit Wirkung für die Zukunft ändern, wenn dafür ein sachlicher Grund besteht (z. B. neue
        Funktionen, geänderte Rechtslage). Änderungen werden mindestens 4 Wochen vor Inkrafttreten per E-Mail oder in der App mitgeteilt.
        Wer nicht einverstanden ist, kann sein Konto bzw. den Verein bis dahin löschen; auf dieses Recht wird in der Mitteilung hingewiesen.
      </p>

      <h2>§ 12 Schlussbestimmungen</h2>
      <p>
        Es gilt das Recht der Bundesrepublik Deutschland; bei Verbrauchern gilt diese Rechtswahl nur, soweit zwingende Verbraucherschutzvorschriften
        des Staates ihres gewöhnlichen Aufenthalts nicht entgegenstehen. Ist der Nutzer Kaufmann, juristische Person des öffentlichen Rechts
        oder ein eingetragener Verein, ist Gerichtsstand der Sitz des Anbieters. Sollte eine Bestimmung unwirksam sein, bleiben die übrigen
        wirksam.
      </p>

      <h2 id="auftragsverarbeitung">Anlage: Auftragsverarbeitung (Art. 28 DSGVO)</h2>
      <p>Zwischen dem Verein (Verantwortlicher) und {operator} (Auftragsverarbeiter) gilt mit der Anmeldung des Vereins:</p>
      <ol>
        <li><strong>Gegenstand und Dauer:</strong> Bereitstellung der Plattform zur Organisation der Volleyballabteilung, für die Dauer der Nutzung durch den Verein.</li>
        <li><strong>Art und Zweck:</strong> Speichern, Anzeigen, Übermitteln (E-Mail, Push) und Löschen der Daten, ausschließlich zur Organisation des Spiel- und Trainingsbetriebs.</li>
        <li><strong>Daten und Betroffene:</strong> Mitglieder, Spieler (auch Minderjährige), Eltern, Trainer, Betreuer, Ansprechpartner: Stamm- und Kontaktdaten, Sportdaten, Zu- und Absagen, Anwesenheit, Kommunikation, Dokumente, Kassenbuchungen.</li>
        <li><strong>Weisungen:</strong> Der Auftragsverarbeiter verarbeitet die Daten nur auf dokumentierte Weisung des Vereins; die Weisungen ergeben sich aus den Einstellungen und Aktionen der Admins in der Plattform.</li>
        <li><strong>Vertraulichkeit:</strong> Personen, die Zugang zu den Daten haben, sind zur Vertraulichkeit verpflichtet.</li>
        <li><strong>Sicherheit (Art. 32):</strong> verschlüsselte Übertragung (HTTPS), technische Trennung der Vereine, Rechteprüfung bei jedem Zugriff, Dateien außerhalb des öffentlichen Bereichs, Anmeldung ohne Passwörter über einmalige Links, Protokoll der Änderungen für Admins.</li>
        <li><strong>Unterauftragsverarbeiter:</strong> ALL-INKL.COM – Neue Medien Münnich, Friedersdorf (Hosting, E-Mail-Versand über deren Mailserver, sofern so eingerichtet); für Push-Benachrichtigungen die Push-Dienste der Browser-Hersteller. Über neue Unterauftragsverarbeiter wird der Verein vorab informiert und kann widersprechen.</li>
        <li><strong>Unterstützung:</strong> Der Auftragsverarbeiter unterstützt den Verein bei Anfragen Betroffener (Auskunft, Export, Löschung – überwiegend direkt in der Plattform möglich) und bei Datenschutzverletzungen, die er unverzüglich meldet.</li>
        <li><strong>Löschung:</strong> Nach Ende der Nutzung werden die Daten des Vereins gelöscht, soweit keine gesetzliche Aufbewahrungspflicht besteht.</li>
        <li><strong>Nachweise:</strong> Der Auftragsverarbeiter stellt dem Verein auf Anfrage die zum Nachweis nötigen Informationen zur Verfügung.</li>
      </ol>
    </>
  );
}
