import type { ReactNode } from 'react';
import type { LegalDetails } from '../lib/api';

/** Name/company and address of the operator as entered by the superadmin. */
export function OperatorAddress({ legal }: { legal: LegalDetails }) {
  return (
    <p>
      {legal.company && <>{legal.company}<br /></>}
      {legal.name ?? <Missing>Name des Betreibers</Missing>}<br />
      {legal.street ?? <Missing>Straße und Hausnummer</Missing>}<br />
      {legal.postal_code || legal.city ? `${legal.postal_code ?? ''} ${legal.city ?? ''}`.trim() : <Missing>PLZ und Ort</Missing>}<br />
      {legal.country}
    </p>
  );
}

export function OperatorContact({ legal }: { legal: LegalDetails }) {
  return (
    <p>
      {legal.phone && <>Telefon: {legal.phone}<br /></>}
      E-Mail: {legal.email ? <a className="text-brand-700 underline" href={`mailto:${legal.email}`}>{legal.email}</a> : <Missing>E-Mail</Missing>}
    </p>
  );
}

export function Missing({ children }: { children: ReactNode }) {
  return <span className="rounded bg-amber-100 px-1 text-amber-900">[{children} – noch nicht eingetragen]</span>;
}

/** "Betreiber" for running text: company or name. */
export const operatorName = (legal: LegalDetails) => legal.company ?? legal.name ?? 'der Betreiber';
