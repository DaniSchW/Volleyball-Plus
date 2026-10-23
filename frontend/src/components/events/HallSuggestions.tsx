import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { api, type Hall } from '../../lib/api';
import { weekdayName } from '../../lib/dates';
import { useTenantPath, useTenantSlug } from '../../lib/queries';
import { courtsLabel } from '../../pages/HallsPage';
import { closureOn } from '../halls/HallClosures';

type Times = { start: string; end: string };

const validOn = (date: string, from: string | null, until: string | null) => (!from || from <= date) && (!until || until >= date);

/**
 * Trainings: the halls available on the chosen day – the team's own hall
 * times first, then free times of all halls. One tap takes over hall and time.
 */
export function HallSuggestions({ teamId, startsAt, onPick }: { teamId: string | null; startsAt: string; onPick: (hall: Hall, times?: Times) => void }) {
  const { t } = useTranslation();
  const slug = useTenantSlug();
  const tp = useTenantPath();
  const halls = useQuery({ queryKey: ['halls', slug], queryFn: async () => (await api<{ data: Hall[] }>(tp('halls'))).data });

  const start = new Date(startsAt);
  if (!halls.data?.length || Number.isNaN(start.getTime())) return null;
  const date = startsAt.slice(0, 10);
  const weekday = ((start.getDay() + 6) % 7) + 1;

  const own = halls.data.flatMap((hall) => hall.bookings
    .filter((b) => b.team.id === teamId && b.weekday === weekday && validOn(date, b.valid_from, b.valid_until))
    .map((b) => ({ hall, b })));
  const available = halls.data.flatMap((hall) => hall.slots
    .filter((s) => s.weekday === weekday && validOn(date, s.valid_from, s.valid_until))
    .map((s) => ({
      hall,
      s,
      taken: hall.bookings.filter((b) => b.weekday === weekday && b.start_time < s.end_time && s.start_time < b.end_time && validOn(date, b.valid_from, b.valid_until)),
    })));

  if (own.length === 0 && available.length === 0) return <p className="text-xs text-slate-500">{t('events.noHallsOn', { day: weekdayName(weekday) })}</p>;

  return (
    <div className="space-y-2 rounded-lg bg-slate-50 p-3">
      <p className="text-sm font-medium text-slate-700">{t('events.hallsOn', { day: weekdayName(weekday) })}</p>
      <ul className="space-y-2">
        {own.map(({ hall, b }) => (
          <li key={b.id} className="flex items-center gap-2 rounded-lg border border-emerald-200 bg-surface p-2 text-sm">
            <span className="min-w-0 flex-1">
              <span className="font-medium">🏟️ {hall.name}</span> · {b.start_time}–{b.end_time}
              <span className="block text-xs text-emerald-700">{t('events.ownHallTime')}{b.courts ? ` · ${courtsLabel(b.courts, hall.courts, t)}` : ''}</span>
              <Closed hall={hall} date={date} />
            </span>
            <button type="button" className="shrink-0 rounded-md bg-brand-solid px-3 py-2 text-xs font-medium text-white" onClick={() => onPick(hall, { start: b.start_time, end: b.end_time })}>
              {t('events.takeHall')}
            </button>
          </li>
        ))}
        {available.map(({ hall, s, taken }) => (
          <li key={s.id} className="flex items-center gap-2 rounded-lg border border-slate-200 bg-surface p-2 text-sm">
            <span className="min-w-0 flex-1">
              <span className="font-medium">🏟️ {hall.name}</span> · {s.start_time}–{s.end_time}
              {s.courts && <span className="text-slate-500"> · {courtsLabel(s.courts, hall.courts, t)}</span>}
              <span className="block text-xs text-slate-500">
                {taken.length === 0 ? t('events.hallFree') : t('events.hallTaken', { teams: taken.map((b) => `${b.team.name} ${b.start_time}–${b.end_time}`).join(', ') })}
              </span>
              <Closed hall={hall} date={date} />
            </span>
            <button type="button" className="shrink-0 rounded-md border border-slate-300 px-3 py-2 text-xs font-medium" onClick={() => onPick(hall)}>
              {t('events.takeHall')}
            </button>
          </li>
        ))}
      </ul>
    </div>
  );
}

/** "🚫 gesperrt (Herbstferien)" when a closure covers the day. */
function Closed({ hall, date }: { hall: Hall; date: string }) {
  const { t } = useTranslation();
  const closure = closureOn(hall, date);
  if (!closure) return null;
  return <span className="block text-xs font-medium text-red-700">🚫 {t('halls.closure.closed')}{closure.reason ? ` (${closure.reason})` : ''}</span>;
}
