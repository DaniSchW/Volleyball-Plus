import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { api, type TeamHallTime } from '../../lib/api';
import { formatDate, weekdayName } from '../../lib/dates';
import { useTenantPath } from '../../lib/queries';
import { courtsLabel } from '../../pages/HallsPage';

/** The team's weekly hall times from the hall plan (hidden when there are none). */
export function TeamHallTimes({ teamId }: { teamId: string }) {
  const { t } = useTranslation();
  const tp = useTenantPath();
  const times = useQuery({
    queryKey: ['hall-times', teamId],
    queryFn: async () => (await api<{ data: TeamHallTime[] }>(tp(`teams/${teamId}/hall-times`))).data,
  });

  if (!times.data?.length) return null;

  return (
    <section className="rounded-xl border border-slate-200 bg-white px-4 py-3">
      <h2 className="mb-1 text-sm font-semibold">{t('halls.teamTimes')}</h2>
      <ul className="space-y-1 text-sm">
        {times.data.map((time) => (
          <li key={time.id}>
            <span className="font-medium">{weekdayName(time.weekday)} {time.start_time}–{time.end_time}</span>
            {' · '}
            {time.hall.address ? (
              <a className="text-brand-700 underline" target="_blank" rel="noreferrer" href={`https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(time.hall.address)}`}>{time.hall.name}</a>
            ) : time.hall.name}
            {time.courts && <span className="text-slate-500"> · {courtsLabel(time.courts, null, t)}</span>}
            {time.valid_from && time.valid_from > new Date().toISOString().slice(0, 10) && <span className="text-slate-500"> · {t('halls.since', { from: formatDate(time.valid_from) })}</span>}
            {time.valid_until && <span className="text-slate-500"> · {t('halls.until', { until: formatDate(time.valid_until) })}</span>}
          </li>
        ))}
      </ul>
    </section>
  );
}
