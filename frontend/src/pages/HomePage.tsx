import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { EventFeed } from '../components/events/EventFeed';
import { NoticesArea } from '../components/NoticesArea';
import { OpenPolls } from '../components/polls/OpenPolls';
import { canCreateEvents } from '../lib/permissions';
import { useTenantMe, useTenantSlug } from '../lib/queries';

/** Start page: notice area on top, then the reply list of upcoming events. */
export function HomePage() {
  const { t } = useTranslation();
  const slug = useTenantSlug();
  const me = useTenantMe();

  return (
    <div className="space-y-6">
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
