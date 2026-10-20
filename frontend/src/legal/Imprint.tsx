import type { LegalDetails } from '../lib/api';
import { OperatorAddress, OperatorContact } from './Operator';

/** Impressum (§ 5 DDG, § 18 Abs. 2 MStV). German only – legal texts are binding in German. */
export function Imprint({ legal }: { legal: LegalDetails }) {
  return (
    <>
      <h2>Angaben gemäß § 5 DDG</h2>
      <OperatorAddress legal={legal} />

      <h2>Kontakt</h2>
      <OperatorContact legal={legal} />

      {legal.vat_id && (
        <>
          <h2>Umsatzsteuer-ID</h2>
          <p>Umsatzsteuer-Identifikationsnummer gemäß § 27a Umsatzsteuergesetz: {legal.vat_id}</p>
        </>
      )}

      <h2>Verantwortlich für den Inhalt nach § 18 Abs. 2 MStV</h2>
      <OperatorAddress legal={legal} />

      <h2>Inhalte der Vereine</h2>
      <p>
        VolleyOrga ist eine Plattform, auf der Vereine ihre Volleyballabteilungen organisieren. Für Inhalte, die Vereine und ihre
        Mitglieder einstellen (Termine, Infos, Chatnachrichten, Dokumente, Profile), ist der jeweilige Verein bzw. die einstellende Person
        verantwortlich. Hinweise auf rechtswidrige Inhalte nehmen wir unter der oben genannten E-Mail-Adresse entgegen und prüfen sie umgehend.
      </p>

      <h2>Haftung für Links</h2>
      <p>
        Die Plattform kann Links zu externen Websites enthalten (z. B. zu Routenplanern). Auf deren Inhalte haben wir keinen Einfluss;
        verantwortlich ist der jeweilige Anbieter. Bei Bekanntwerden von Rechtsverletzungen entfernen wir solche Links umgehend.
      </p>

      <h2>Verbraucherstreitbeilegung</h2>
      <p>Wir sind nicht bereit und nicht verpflichtet, an Streitbeilegungsverfahren vor einer Verbraucherschlichtungsstelle teilzunehmen.</p>
    </>
  );
}
