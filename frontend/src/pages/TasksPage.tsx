import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { api, type MyTasksRow } from '../lib/api';
import { formatDay, formatTime } from '../lib/dates';
import { useTenantPath, useTenantSlug } from '../lib/queries';
import { Card, Loading, Notice, PageTitle } from '../components/ui';
import { TypeIcon } from '../components/events/EventCard';

/** My upcoming tasks (and my children's) and helper services with free places. */
export function TasksPage() {
  const { t } = useTranslation();
  const slug = useTenantSlug();
  const tp = useTenantPath();
  const rows = useQuery({ queryKey: ['my-tasks', slug], queryFn: async () => (await api<{ data: MyTasksRow[] }>(tp('tasks'))).data });

  if (rows.isPending) return <Loading />;
  if (rows.isError) return <Notice tone="error">{t('errors.generic')}</Notice>;

  const mine = rows.data.filter((r) => r.mine.length > 0);
  const open = rows.data.filter((r) => r.event.type === 'helper' && r.open_slots > 0);

  return (
    <div className="space-y-4">
      <PageTitle>{t('menu.tasks')}</PageTitle>
      <Card title={t('tasks.mine')}>
        {mine.length === 0 && <p className="text-sm text-slate-500">{t('tasks.mineNone')}</p>}
        <ul className="divide-y divide-slate-100">
          {mine.map((row) => (
            <li key={row.event.id}>
              <Link to={`/${slug}/events/${row.event.id}`} className="block py-2">
                <span className="block text-xs text-slate-500">{formatDay(row.event.starts_at)} · {formatTime(row.event.starts_at)} · {row.event.teams.join(', ')}</span>
                <span className="flex items-center gap-2 font-medium"><TypeIcon type={row.event.type} />{row.event.title}</span>
                <span className="block text-sm text-slate-700">
                  {row.mine.map((task) => `${task.title} (${task.mine.join(', ')})`).join(' · ')}
                </span>
              </Link>
            </li>
          ))}
        </ul>
      </Card>
      <Card title={t('tasks.helpWanted')}>
        {open.length === 0 && <p className="text-sm text-slate-500">{t('tasks.helpWantedNone')}</p>}
        <ul className="divide-y divide-slate-100">
          {open.map((row) => (
            <li key={row.event.id}>
              <Link to={`/${slug}/events/${row.event.id}`} className="flex items-center justify-between gap-3 py-2">
                <span className="min-w-0">
                  <span className="block text-xs text-slate-500">{formatDay(row.event.starts_at)} · {formatTime(row.event.starts_at)}</span>
                  <span className="block truncate font-medium">🤝 {row.event.title}</span>
                </span>
                <span className="shrink-0 text-sm text-brand-700">{t('tasks.freePlaces', { count: row.open_slots })}</span>
              </Link>
            </li>
          ))}
        </ul>
      </Card>
    </div>
  );
}
