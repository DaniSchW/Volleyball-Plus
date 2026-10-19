import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { api, errorText } from '../lib/api';
import { formatLongDay, formatTime } from '../lib/dates';
import { useTenantMe, useTenantPath, useTenantSlug } from '../lib/queries';
import { Card, Loading, Notice, PageTitle } from '../components/ui';

interface ActivityEntry {
  id: string;
  action: 'created' | 'updated' | 'deleted';
  type: string;
  label: string;
  actor: string | null;
  by_system: boolean;
  created_at: string;
}

const ACTION_STYLE: Record<ActivityEntry['action'], string> = {
  created: 'bg-emerald-100 text-emerald-800',
  updated: 'bg-sky-100 text-sky-800',
  deleted: 'bg-red-100 text-red-800',
};

/** Admins: what changed in the club in the last 7 days and by whom. */
export function ActivityPage() {
  const { t, i18n } = useTranslation();
  const slug = useTenantSlug();
  const tp = useTenantPath();
  const me = useTenantMe();
  const log = useQuery({
    queryKey: ['activity', slug],
    queryFn: async () => (await api<{ data: ActivityEntry[] }>(tp('activity'))).data,
    enabled: !!me.data?.is_admin,
  });

  if (me.isPending || (me.data?.is_admin && log.isPending)) return <Loading />;
  if (!me.data?.is_admin) return <Notice tone="error">{t('tenant.noAccess')}</Notice>;
  if (log.isError) return <Notice tone="error">{errorText(log.error, t('errors.generic'))}</Notice>;

  const days = new Map<string, ActivityEntry[]>();
  for (const entry of log.data ?? []) {
    const day = formatLongDay(entry.created_at);
    days.set(day, [...(days.get(day) ?? []), entry]);
  }

  return (
    <div className="space-y-4">
      <PageTitle>{t('activity.title')}</PageTitle>
      <p className="text-sm text-slate-600">{t('activity.hint')}</p>
      {days.size === 0 && <Notice>{t('activity.none')}</Notice>}
      {[...days.entries()].map(([day, entries]) => (
        <Card key={day} title={day}>
          <ul className="-mx-4 divide-y divide-slate-100">
            {entries.map((e) => (
              <li key={e.id} className="flex items-start gap-3 px-4 py-2 text-sm">
                <span className="w-12 shrink-0 tabular-nums text-slate-500">{formatTime(e.created_at)}</span>
                <span className="min-w-0 flex-1">
                  <span className={`mr-2 inline-block rounded px-1.5 py-0.5 text-xs font-medium ${ACTION_STYLE[e.action]}`}>{t(`activity.actions.${e.action}`)}</span>
                  <span className="text-slate-500">{i18n.exists(`activity.types.${e.type}`) ? t(`activity.types.${e.type}`) : e.type}</span>
                  <span className="block truncate font-medium">{e.label}</span>
                  <span className="block text-xs text-slate-500">{e.by_system ? t('activity.system') : t('activity.by', { name: e.actor ?? t('activity.deletedPerson') })}</span>
                </span>
              </li>
            ))}
          </ul>
        </Card>
      ))}
    </div>
  );
}
