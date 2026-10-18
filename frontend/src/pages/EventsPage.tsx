import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { EventFeed } from '../components/events/EventFeed';
import { PageTitle } from '../components/ui';
import { canCreateEvents } from '../lib/permissions';
import { useTenantMe, useTenantSlug } from '../lib/queries';

export function EventsPage() {
  const { t } = useTranslation();
  const slug = useTenantSlug();
  const me = useTenantMe();
  const [past, setPast] = useState(false);

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between gap-3">
        <PageTitle>{t('menu.events')}</PageTitle>
        {canCreateEvents(me.data) && (
          <Link to={`/${slug}/events/new`} className="-mt-4 rounded-lg bg-brand-700 px-3 py-2 text-sm font-medium text-white">{t('events.create')}</Link>
        )}
      </div>
      <div role="tablist" className="flex gap-1 rounded-lg bg-slate-100 p-1">
        {[false, true].map((value) => (
          <button
            key={String(value)}
            role="tab"
            type="button"
            aria-selected={past === value}
            onClick={() => setPast(value)}
            className={`min-h-10 flex-1 rounded-md text-sm ${past === value ? 'bg-white font-medium shadow-sm' : 'text-slate-600'}`}
          >
            {value ? t('events.past') : t('events.upcoming')}
          </button>
        ))}
      </div>
      <EventFeed past={past} key={String(past)} />
    </div>
  );
}
