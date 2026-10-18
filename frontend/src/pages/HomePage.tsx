import { useTranslation } from 'react-i18next';

/**
 * Start page: notice area on top, then the list of upcoming events with
 * accept/decline. The event list (with infinite scroll) follows in phase 2.
 */
export function HomePage() {
  const { t } = useTranslation();

  return (
    <div className="space-y-6">
      <section aria-labelledby="notices-heading" className="rounded-xl border border-slate-200 bg-white p-4">
        <h2 id="notices-heading" className="text-sm font-semibold uppercase tracking-wide text-slate-500">
          {t('home.infoArea')}
        </h2>
        <p className="mt-2 text-sm text-slate-600">{t('home.noNotices')}</p>
      </section>

      <section aria-labelledby="upcoming-heading">
        <h2 id="upcoming-heading" className="mb-3 text-lg font-semibold">
          {t('home.upcoming')}
        </h2>
        <p className="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
          {t('home.noEvents')}
        </p>
      </section>
    </div>
  );
}
