import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { EventFeed } from '../components/events/EventFeed';
import { NoticesArea } from '../components/NoticesArea';
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
      <section aria-labelledby="upcoming-heading">
        <div className="mb-3 flex items-center justify-between gap-3">
          <h2 id="upcoming-heading" className="text-lg font-semibold">{t('home.upcoming')}</h2>
          {canCreateEvents(me.data) && (
            <Link to={`/${slug}/events/new`} className="rounded-lg bg-brand-700 px-3 py-2 text-sm font-medium text-white">
              {t('events.create')}
            </Link>
          )}
        </div>
        <EventFeed />
      </section>
    </div>
  );
}
