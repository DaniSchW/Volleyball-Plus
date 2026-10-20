import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { EventFeed } from '../components/events/EventFeed';
import { NoticesArea } from '../components/NoticesArea';
import { OpenPolls } from '../components/polls/OpenPolls';
import { canCreateEvents } from '../lib/permissions';
import { useTenantMe, useTenantSlug } from '../lib/queries';

/** Start page: notice area on top, then the reply list of upcoming events. */
export function HomePage() {
  const { t, i18n } = useTranslation();
  const slug = useTenantSlug();
  const me = useTenantMe();

  const firstName = me.data?.persons.find((p) => p.relation !== 'guardian')?.first_name;
  const hour = new Date().getHours();
  const greeting = t(hour < 11 ? 'home.morning' : hour < 18 ? 'home.day' : 'home.evening', { name: firstName ?? '' }).replace(/,?\s*!$/, '!');

  return (
    <div className="space-y-6">
      <div className="vo-rise relative overflow-hidden rounded-2xl bg-gradient-to-br from-brand-700 via-brand-600 to-sky-500 p-5 text-white shadow-lg shadow-brand-700/20">
        <span aria-hidden="true" className="absolute -right-6 -top-6 text-8xl opacity-20">🏐</span>
        <p className="text-sm text-white/80">{new Intl.DateTimeFormat(i18n.resolvedLanguage ?? 'de', { weekday: 'long', day: 'numeric', month: 'long' }).format(new Date())}</p>
        <p className="mt-1 text-2xl font-bold tracking-tight">{greeting}</p>
        <p className="mt-1 text-sm text-white/85">{t('home.tagline')}</p>
      </div>
      <NoticesArea />
      <OpenPolls />
      <section aria-labelledby="upcoming-heading">
        <div className="mb-3 flex items-center justify-between gap-3">
          <h2 id="upcoming-heading" className="flex items-center gap-2 text-lg font-semibold"><span aria-hidden="true" className="h-5 w-1.5 rounded-full bg-ball-400" />{t('home.upcoming')}</h2>
          {canCreateEvents(me.data) && (
            <Link to={`/${slug}/events/new`} className="rounded-lg bg-brand-700 px-3 py-2 text-sm font-medium text-white">
              {t('events.create')}
            </Link>
          )}
        </div>
        {(me.data?.persons.length ?? 0) > 0 && (
          <Link to={`/${slug}/replies`} className="mb-3 flex min-h-11 items-center gap-2 rounded-lg border border-brand-100 bg-white px-3 text-sm font-medium text-brand-700">
            <span aria-hidden="true">📆</span> {t('rules.link')}
          </Link>
        )}
        <EventFeed />
      </section>
    </div>
  );
}
