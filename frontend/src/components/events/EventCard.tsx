import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import type { EventSummary } from '../../lib/api';
import { formatDateTime, formatDay, formatTime } from '../../lib/dates';
import { useTenantSlug } from '../../lib/queries';
import { RsvpControl } from './RsvpControl';

/** Colour per type of event: training, match, extra event, helper service. */
export const TYPE_COLORS: Record<EventSummary['type'], { stripe: string; chip: string; tile: string }> = {
  training: { stripe: 'border-l-emerald-500', chip: 'bg-emerald-100 text-emerald-800', tile: 'from-emerald-500 to-emerald-600' },
  match: { stripe: 'border-l-orange-500', chip: 'bg-orange-100 text-orange-800', tile: 'from-orange-500 to-orange-600' },
  extra: { stripe: 'border-l-violet-500', chip: 'bg-violet-100 text-violet-800', tile: 'from-violet-500 to-violet-600' },
  helper: { stripe: 'border-l-amber-500', chip: 'bg-amber-100 text-amber-800', tile: 'from-amber-500 to-amber-600' },
};

/** Calendar leaf: weekday, day, month – coloured by the type of event. */
function DateTile({ iso, type, muted }: { iso: string; type: EventSummary['type']; muted: boolean }) {
  const { i18n } = useTranslation();
  const date = new Date(iso);
  const part = (o: Intl.DateTimeFormatOptions) => new Intl.DateTimeFormat(i18n.resolvedLanguage ?? 'de', o).format(date);
  return (
    <div aria-hidden="true" className={`flex w-14 shrink-0 flex-col items-center overflow-hidden rounded-xl bg-surface text-center shadow-sm ring-1 ring-slate-200 ${muted ? 'grayscale' : ''}`}>
      <span className={`w-full bg-gradient-to-b py-0.5 text-[0.65rem] font-semibold uppercase tracking-wide text-white ${TYPE_COLORS[type].tile}`}>{part({ weekday: 'short' })}</span>
      <span className="pt-0.5 text-xl font-bold leading-6 text-slate-900">{part({ day: 'numeric' })}</span>
      <span className="pb-1 text-[0.65rem] font-medium uppercase text-slate-500">{part({ month: 'short' })}</span>
    </div>
  );
}

/** Type marker: trophy for match days, nothing for trainings, calendar for other dates. */
export function TypeIcon({ type }: { type: EventSummary['type'] }) {
  const { t } = useTranslation();
  if (type === 'training') return null;
  return (
    <span role="img" aria-label={t(`events.types.${type}`)} className="text-base">
      {{ match: '🏆', extra: '📅', helper: '🤝' }[type]}
    </span>
  );
}

/** "Heute" / "Morgen" / "in 3 Tagen" for the coming week, otherwise nothing. */
function RelativeDay({ iso }: { iso: string }) {
  const { t } = useTranslation();
  const day = (d: Date) => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
  const days = Math.round((day(new Date(iso)) - day(new Date())) / 86_400_000);
  if (days < 0 || days > 6) return null;
  return (
    <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${days === 0 ? 'bg-brand-solid text-white' : 'bg-brand-50 text-brand-700'}`}>
      {days === 0 ? t('events.today') : days === 1 ? t('events.tomorrow') : t('events.inDays', { count: days })}
    </span>
  );
}

/** Replies as one bar (yes / maybe / no / open), with a marker at the minimum. */
function ReplyBar({ event }: { event: EventSummary }) {
  const { yes, maybe, no, open } = event.counts;
  const total = Math.max(yes + maybe + no + open, event.max_participants ?? 0, 1);
  const pct = (n: number) => `${(n / total) * 100}%`;
  const min = event.min_participants ?? 0;
  return (
    <div aria-hidden="true" className="relative mt-3 flex h-2 overflow-hidden rounded-full bg-slate-100">
      <span className="bg-green-500 transition-all" style={{ width: pct(yes) }} />
      <span className="bg-amber-400 transition-all" style={{ width: pct(maybe) }} />
      <span className="bg-red-400 transition-all" style={{ width: pct(no) }} />
      {min > 0 && min < total && <span className="absolute inset-y-0 w-0.5 bg-slate-500" style={{ left: pct(min) }} />}
    </div>
  );
}

/** Does the training take place? Reached (green), still open (amber) or not reachable any more (red). */
export function minimumState(event: EventSummary): 'reached' | 'open' | 'unreachable' | null {
  if (event.status === 'cancelled' || event.response_mode !== 'rsvp') return null;
  return event.minimum_state ?? (event.below_minimum ? 'open' : null);
}

export function MinimumNotice({ event }: { event: EventSummary }) {
  const { t } = useTranslation();
  const state = minimumState(event);
  if (!state) return null;
  const style = {
    reached: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
    open: 'bg-amber-50 text-amber-800 ring-amber-200',
    unreachable: 'bg-red-50 text-red-800 ring-red-200',
  }[state];
  return (
    <p className={`rounded-lg px-2.5 py-1.5 text-xs font-medium ring-1 ${style}`}>
      <span aria-hidden="true">{{ reached: '✅', open: '⚠️', unreachable: '❌' }[state]} </span>
      {state === 'reached' && t('events.minimumReached', { min: event.min_participants })}
      {state === 'open' && t('events.belowMinimum', { min: event.min_participants })}
      {state === 'unreachable' && (
        <>
          <strong>{t('events.minimumUnreachable')}</strong> – {t('events.minimumPossible', { possible: event.minimum_possible ?? 0, min: event.min_participants })}
        </>
      )}
    </p>
  );
}

export function EventCard({ event }: { event: EventSummary }) {
  const { t } = useTranslation();
  const slug = useTenantSlug();
  const cancelled = event.status === 'cancelled';
  const minimum = minimumState(event);
  const rsvp = event.response_mode === 'rsvp' && !cancelled;
  const who = [event.teams.map((team) => team.name).join(', '), event.training_group?.name, event.my.some((r) => r.is_guest) && t('events.asGuest')]
    .filter(Boolean).join(' · ');

  return (
    <article
      className={`vo-rise overflow-hidden rounded-2xl border border-l-4 shadow-[0_1px_2px_rgb(15_23_42/0.04),0_6px_20px_-8px_rgb(15_23_42/0.1)] transition hover:shadow-md ${TYPE_COLORS[event.type].stripe} ${cancelled ? 'border-slate-200 bg-slate-100 opacity-70' : minimum === 'unreachable' ? 'border-red-300 bg-surface' : minimum === 'open' ? 'border-amber-300 bg-surface' : minimum === 'reached' ? 'border-emerald-300 bg-surface' : 'border-slate-200 bg-surface'}`}
      aria-label={event.title}
    >
      <Link to={`/${slug}/events/${event.id}`} className="block p-4">
        <div className="flex items-start gap-3">
          <DateTile iso={event.starts_at} type={event.type} muted={cancelled} />
          <div className="min-w-0 flex-1">
            <p className="flex flex-wrap items-center gap-1.5">
              <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${TYPE_COLORS[event.type].chip}`}>{t(`events.types.${event.type}`)}</span>
              {!cancelled && <RelativeDay iso={event.starts_at} />}
            </p>
            <h3 className={`mt-1 flex items-center gap-2 text-base font-semibold leading-snug text-slate-900 ${cancelled ? 'line-through' : ''}`}>
              <TypeIcon type={event.type} />
              <span className="truncate">{event.title}</span>
            </h3>
            <p className="mt-0.5 flex flex-wrap items-center gap-x-3 text-sm text-slate-600">
              <span className="sr-only">{formatDay(event.starts_at)} · </span>
              <span className="whitespace-nowrap"><span aria-hidden="true">🕒 </span>{formatTime(event.starts_at)}–{formatTime(event.ends_at)}</span>
              {event.location_name && <span className="min-w-0 truncate"><span aria-hidden="true">📍 </span>{event.location_name}</span>}
            </p>
            {who && <p className="truncate text-sm text-slate-500"><span aria-hidden="true">👥 </span>{who}</p>}
          </div>
          {/* Logos only once the opponent has one – otherwise just "gegen" would be shown. */}
          {event.type === 'match' && event.opponent_logo_url && (
            <div className="flex shrink-0 flex-col items-center gap-0.5">
              {event.own_logo_url && <img src={event.own_logo_url} alt="" className="vo-logo size-9 rounded object-contain" />}
              <span className="text-[0.65rem] uppercase text-slate-400">{t('events.versus')}</span>
              <img src={event.opponent_logo_url} alt={event.opponent?.name ?? ''} className="vo-logo size-9 rounded object-contain" />
            </div>
          )}
        </div>

        {cancelled && <p className="mt-2 text-sm font-medium text-red-700">{t('events.cancelled')}</p>}
        {event.response_mode === 'info' && !cancelled && event.type !== 'helper' && (
          <p className="mt-2 text-sm text-slate-600"><span aria-hidden="true">ℹ️ </span>{t('events.infoOnly')}</p>
        )}
        {event.task_summary && !cancelled && (
          <p className="mt-2 inline-flex items-center gap-1 rounded-lg bg-amber-50 px-2 py-1 text-xs font-medium text-amber-900">
            <span aria-hidden="true">📋</span>
            {event.task_summary.slots === 0
              ? t('tasks.summaryOpen', { count: event.task_summary.count, taken: event.task_summary.taken })
              : t('tasks.summary', { count: event.task_summary.count, taken: event.task_summary.taken, slots: event.task_summary.slots })}
          </p>
        )}

        {rsvp && (
          <>
            <ReplyBar event={event} />
            <p className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-slate-600">
              <span className="font-semibold text-green-700">✓ {event.counts.yes}{event.max_participants ? ` / ${event.max_participants}` : ''}</span>
              <span className="text-amber-700">? {event.counts.maybe}</span>
              <span className="text-red-700">✕ {event.counts.no}</span>
              <span>{t('events.open', { count: event.counts.open })}</span>
              {event.is_full && <span className="rounded-full bg-slate-800 px-2 font-medium text-slate-50">{t('events.full')}</span>}
            </p>
            {minimum && <div className="mt-2"><MinimumNotice event={event} /></div>}
          </>
        )}
      </Link>

      {rsvp && event.my.length > 0 && (
        <div className="space-y-2 border-t border-slate-100 bg-slate-50/70 px-4 py-3">
          {event.rsvp_deadline && !event.deadline_passed && (
            <p className="text-xs text-slate-500"><span aria-hidden="true">⏳ </span>{t('events.deadlineUntil', { date: formatDateTime(event.rsvp_deadline) })}</p>
          )}
          {event.my.map((reply) => (
            <RsvpControl key={reply.person_id} event={event} reply={reply} showName={event.my.length > 1 || !reply.is_self} />
          ))}
        </div>
      )}
      {rsvp && event.my.length === 0 && event.rsvp_deadline && !event.deadline_passed && (
        <p className="-mt-2 px-4 pb-3 text-xs text-slate-500"><span aria-hidden="true">⏳ </span>{t('events.deadlineUntil', { date: formatDateTime(event.rsvp_deadline) })}</p>
      )}
    </article>
  );
}
