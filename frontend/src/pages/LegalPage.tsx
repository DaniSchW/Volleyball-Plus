import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router';
import { api, type LegalDetails } from '../lib/api';
import { formatDate } from '../lib/dates';
import { Loading, Notice } from '../components/ui';
import { LegalLinks } from '../components/LegalLinks';
import { Imprint } from '../legal/Imprint';
import { Privacy } from '../legal/Privacy';
import { Terms } from '../legal/Terms';

export type LegalKind = 'imprint' | 'privacy' | 'terms';

export const useLegal = () => useQuery({ queryKey: ['legal'], queryFn: async () => (await api<{ data: LegalDetails }>('/legal')).data, staleTime: 3600_000 });

/** Impressum, Datenschutz, AGB – the texts are German (binding version). */
export function LegalPage({ kind }: { kind: LegalKind }) {
  const { t, i18n } = useTranslation();
  const navigate = useNavigate();
  const legal = useLegal();

  return (
    <div className="mx-auto max-w-3xl space-y-4 p-4">
      <div>
        <button type="button" className="text-sm text-brand-700" onClick={() => (window.history.length > 1 ? navigate(-1) : navigate('/'))}>‹ {t('legal.back')}</button>
        <h1 className="mt-2 text-2xl font-semibold">{t(`legal.${kind}`)}</h1>
        {legal.data?.updated_on && <p className="text-xs text-slate-500">{t('legal.updated', { date: formatDate(legal.data.updated_on) })}</p>}
      </div>
      {i18n.resolvedLanguage !== 'de' && <Notice>{t('legal.germanOnly')}</Notice>}
      {legal.isPending && <Loading />}
      {legal.data && !legal.data.complete && <Notice tone="error">{t('legal.incomplete')}</Notice>}
      {legal.data && (
        <article className="legal space-y-3 rounded-xl border border-slate-200 bg-white p-4 text-sm leading-relaxed text-slate-800 [&_h2]:mt-5 [&_h2]:text-base [&_h2]:font-semibold [&_ol]:list-decimal [&_ol]:space-y-1 [&_ol]:pl-5 [&_ul]:list-disc [&_ul]:space-y-1 [&_ul]:pl-5">
          {kind === 'imprint' && <Imprint legal={legal.data} />}
          {kind === 'privacy' && <Privacy legal={legal.data} />}
          {kind === 'terms' && <Terms legal={legal.data} />}
        </article>
      )}
      <LegalLinks />
      <p className="text-center text-xs text-slate-400"><Link to="/">{t('app.name')}</Link></p>
    </div>
  );
}
