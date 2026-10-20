import { Link } from 'react-router';
import type { LegalDetails } from '../lib/api';
import { OperatorAddress, OperatorContact, operatorName } from './Operator';

/** Datenschutzerklärung (Art. 13, 14 DSGVO). German only – legal texts are binding in German. */
export function Privacy({ legal }: { legal: LegalDetails }) {
  const operator = operatorName(legal);
  return (
    <>
      <h2>1. Verantwortlicher</h2>
      <p>Verantwortlich für die Datenverarbeitung auf dieser Plattform im Sinne der Datenschutz-Grundverordnung (DSGVO) ist:</p>
      <OperatorAddress legal={legal} />
      <OperatorContact legal={legal} />
      <p>
        <strong>Daten in den Vereinen:</strong> Jeder Verein nutzt VolleyOrga für seine Volleyballabteilung. Für die Daten, die im Rahmen
        des Vereins verarbeitet werden (Mitglieder- und Spielerprofile, Mannschaften, Termine, Zu- und Absagen, Anwesenheit, Chat, Kasse,
        Dokumente), ist der jeweilige <strong>Verein Verantwortlicher</strong>. {operator} verarbeitet diese Daten als Auftragsverarbeiter
        nach Art. 28 DSGVO im Auftrag des Vereins (siehe <Link className="text-brand-700 underline" to="/agb">AGB, Anlage Auftragsverarbeitung</Link>).
        Anfragen zu diesen Daten richtest du am besten an deinen Verein; wir leiten Anfragen, die uns erreichen, an den Verein weiter.
      </p>

      <h2>2. Das Wichtigste in Kürze</h2>
      <ul>
        <li>Kein Tracking, keine Analyse-Tools, keine Werbenetzwerke, keine Social-Media-Plugins, keine extern geladenen Schriften.</li>
        <li>Nur ein technisch notwendiges Cookie für die Anmeldung; keine Einwilligung nötig.</li>
        <li>Anmeldung ohne Passwort über einen Link per E-Mail.</li>
        <li>Du kannst deine Daten jederzeit herunterladen und dein Konto selbst löschen.</li>
        <li>Wir verkaufen keine Daten und geben sie nicht zu Werbezwecken weiter.</li>
      </ul>

      <h2>3. Hosting und Server-Protokolle</h2>
      <p>
        Die Plattform wird bei der ALL-INKL.COM – Neue Medien Münnich, Inhaber René Münnich, Hauptstraße 68, 02742 Friedersdorf,
        Deutschland, auf Servern in Deutschland betrieben. Mit dem Hoster besteht ein Vertrag zur Auftragsverarbeitung. Beim Aufruf der
        Plattform verarbeitet der Server technisch notwendige Daten: IP-Adresse, Datum und Uhrzeit, aufgerufene Adresse, übertragene
        Datenmenge, Browser und Betriebssystem. Das dient dem sicheren Betrieb (Art. 6 Abs. 1 lit. f DSGVO – berechtigtes Interesse an
        Stabilität und Sicherheit). Die Protokolle werden vom Hoster nach kurzer Zeit gelöscht.
      </p>

      <h2>4. Konto und Anmeldung</h2>
      <p>
        Für dein Konto speichern wir deine E-Mail-Adresse, deine Spracheinstellung, wann du dich zuletzt angemeldet bzw. die App genutzt
        hast und deine angemeldeten Geräte (Browserkennung, IP-Adresse beim Anmelden, letzte Nutzung). Zum Anmelden schicken wir dir einen
        Link per E-Mail, der nur kurz gültig ist und nur einmal funktioniert. Nach der Anmeldung setzen wir ein Sitzungs-Cookie
        („vp_session“), das dich bis zu 90 Tage angemeldet hält; es wird nicht für andere Zwecke verwendet. Rechtsgrundlage ist die
        Bereitstellung der Plattform (Art. 6 Abs. 1 lit. b DSGVO) bzw. § 25 Abs. 2 TDDDG für das technisch notwendige Cookie.
      </p>

      <h2>5. Daten im Verein</h2>
      <p>Je nachdem, was du bzw. dein Verein einträgt, werden verarbeitet:</p>
      <ul>
        <li>Profil: Name, Geschlecht, Geburtsdatum, Kontaktdaten, Adresse, Trikotnummer, Positionen, Körpergröße und Reichhöhen, Profilbild, Schiedsrichterlizenz – mit Einstellungen, wer welche Angaben sieht.</li>
        <li>Mannschaften, Rollen (Spieler, Trainer, Betreuer), Trainingsgruppen und Quernominierungen.</li>
        <li>Termine, Zu- und Absagen mit Gründen, Daueranworten, tatsächliche Anwesenheit (von Trainern erfasst) und die daraus erstellte Statistik.</li>
        <li>Aufgaben und Helferdienste, Umfragen und Stimmen, Infos, Chatnachrichten und -anhänge, Dokumente, Kassenbuchungen, Hallen und Ansprechpartner.</li>
        <li>Ein Änderungsprotokoll für die Admins des Vereins (wer was geändert hat), das nach 7 Tagen gelöscht wird.</li>
      </ul>
      <p>
        Rechtsgrundlage ist die Mitgliedschaft bzw. die Nutzung der Plattform (Art. 6 Abs. 1 lit. b DSGVO) und das berechtigte Interesse
        des Vereins an der Organisation des Spiel- und Trainingsbetriebs (Art. 6 Abs. 1 lit. f DSGVO).
      </p>
      <p>
        <strong>Kinder und Jugendliche:</strong> Profile von Minderjährigen legen die Eltern bzw. der Verein an. Eltern bestimmen, ob und
        was ihr Kind selbst in der App tun darf (z. B. zusagen oder chatten). Mit dem 18. Geburtstag wird das Profil automatisch vom
        Elternkonto gelöst.
      </p>

      <h2>6. E-Mails</h2>
      <p>
        Wir verschicken E-Mails, die zur Nutzung gehören: Anmeldelinks, Einladungen, Benachrichtigungen nach deinen Einstellungen im
        Profil (z. B. Terminänderungen) und wichtige Hinweise zu deinem Konto (z. B. vor einer Löschung wegen Inaktivität).
        <strong> Werbe-E-Mails</strong> (Neuigkeiten und Angebote rund um VolleyOrga) erhältst du nur, wenn du im Profil ausdrücklich
        eingewilligt hast (Art. 6 Abs. 1 lit. a DSGVO, § 7 Abs. 2 UWG). Die Einwilligung kannst du jederzeit im Profil oder per E-Mail an uns
        widerrufen. Die E-Mails werden über den Mailserver des Hosters bzw. den im Betrieb eingestellten Mailserver versendet.
      </p>

      <h2>7. Push-Benachrichtigungen</h2>
      <p>
        Wenn du Push-Benachrichtigungen einschaltest, speichern wir die Push-Adresse deines Browsers. Die Nachrichten werden verschlüsselt
        über den Push-Dienst des Browser-Herstellers zugestellt (z. B. Google, Apple, Mozilla); der Dienst sieht dabei nur die
        verschlüsselte Nachricht und die Push-Adresse. Rechtsgrundlage ist deine Einwilligung (Art. 6 Abs. 1 lit. a DSGVO), die du
        jederzeit im Profil oder in den Browser-Einstellungen widerrufen kannst. Bei Anbietern außerhalb der EU kann eine Übermittlung in
        Drittländer (z. B. USA) stattfinden; sie erfolgt auf Grundlage des EU-US Data Privacy Framework bzw. von Standardvertragsklauseln.
      </p>

      <h2>8. Kalender-Abo und externe Links</h2>
      <p>
        Für das Kalender-Abo erzeugen wir eine geheime Adresse; wer sie kennt, sieht deine Termine. Du kannst jederzeit eine neue Adresse
        erzeugen, dann wird die alte ungültig. Links zu Routenplanern (Google Maps) werden erst beim Antippen geöffnet; erst dann gelten
        die Datenschutzbestimmungen des jeweiligen Anbieters.
      </p>

      <h2>9. Speicherdauer und Löschung</h2>
      <ul>
        <li>Du kannst dein Konto jederzeit im Profil löschen; dein Profil wird dann in allen Vereinen gelöscht.</li>
        <li>Konten, die länger als 2 Jahre nicht genutzt wurden, kündigen wir per E-Mail an und löschen sie 14 Tage später, wenn du dich nicht wieder anmeldest.</li>
        <li>Kassenbuchungen bleiben für die Buchführung des Vereins ohne Personenbezug erhalten, Chatnachrichten ohne Namen.</li>
        <li>Das Änderungsprotokoll wird nach 7 Tagen gelöscht, nie gesendete Chat-Uploads nach einem Tag.</li>
        <li>Löscht ein Verein seine Daten oder wird er von der Plattform entfernt, werden alle Daten des Vereins gelöscht.</li>
      </ul>

      <h2>10. Deine Rechte</h2>
      <p>
        Du hast das Recht auf Auskunft (Art. 15 DSGVO), Berichtigung (Art. 16), Löschung (Art. 17), Einschränkung der Verarbeitung
        (Art. 18), Datenübertragbarkeit (Art. 20) und Widerspruch gegen Verarbeitungen auf Grundlage berechtigter Interessen (Art. 21).
        Erteilte Einwilligungen kannst du jederzeit mit Wirkung für die Zukunft widerrufen. Viele Rechte kannst du direkt in der App
        ausüben: Daten herunterladen und Profil löschen unter „Persönliche Daten“, Konto löschen im Profil. Außerdem hast du das Recht,
        dich bei einer Datenschutz-Aufsichtsbehörde zu beschweren, z. B. der Behörde deines Bundeslandes.
      </p>

      <h2>11. Sicherheit</h2>
      <p>
        Die Verbindung ist immer verschlüsselt (HTTPS). Die Daten der Vereine sind technisch voneinander getrennt, Dateien liegen außerhalb
        des öffentlich erreichbaren Bereichs und werden nur nach Prüfung der Rechte ausgeliefert. Eine automatisierte Entscheidungsfindung
        oder Profilbildung findet nicht statt.
      </p>
    </>
  );
}
