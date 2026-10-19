import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import type { EventSummary } from '../../lib/api';
import { formatDateTime, formatDay, formatTime } from '../../lib/dates';
import { useTenantSlug } from '../../lib/queries';
import { RsvpControl } from './RsvpControl';

/** Type marker: trophy for match days, nothing for trainings, calendar for other dates. */
/** Colour per type of event: training, match, extra event, helper service. */
export const TYPE_COLORS: Record<EventSummary['type'], { stripe: string; chip: string }> = {
  training: { stripe: 'border-l-emerald-500', chip: 'bg-emerald-100 text-emerald-800' },
  match: { stripe: 'border-l-orange-500', chip: 'bg-orange-100 text-orange-800' },
  extra: { stripe: 'border-l-violet-500', chip: 'bg-violet-100 text-violet-800' },
  helper: { stripe: 'border-l-amber-500', chip: 'bg-amber-100 text-amber-800' },
};

export function TypeIcon({ type }: { type: EventSummary['type'] }) {
  const { t } = useTranslation();
  if (type === 'training') return null;
  return (
    <span role="img" aria-label={t(`events.types.${type}`)} className="text-base">
      {{ match: '🏆', extra: '📅', helper: '🤝' }[type]}
    </span>
  );
}

export function EventCard({ event }: { event: EventSummary }) {
  const { t } = useTranslation();
  const slug = useTenantSlug();
  const cancelled = event.status === 'cancelled';
  const highlight = event.below_minimum && !cancelled;

  return (
    <article
      className={`rounded-xl border border-l-4 p-4 shadow-sm ${TYPE_COLORS[event.type].stripe} ${cancelled ? 'border-slate-200 bg-slate-100 opacity-70' : highlight ? 'border-amber-300 bg-amber-50' : 'border-slate-200 bg-white'}`}
      aria-label={event.title}
    >
      <Link to={`/${slug}/events/${event.id}`} className="block">
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0">
            <p className="flex flex-wrap items-center gap-2 text-sm text-slate-500">
              <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${TYPE_COLORS[event.type].chip}`}>{t(`events.types.${event.type}`)}</span>
              {formatDay(event.starts_at)} · {formatTime(event.starts_at)}–{formatTime(event.ends_at)}
            </p>
            <h3 className={`flex items-center gap-2 text-base font-semibold ${cancelled ? 'line-through' : ''}`}>
              <TypeIcon type={event.type} />
              <span className="truncate">{event.title}</span>
            </h3>
            <p className="truncate text-sm text-slate-600">
              {[event.teams.map((team) => team.name).join(', '), event.training_group?.name, event.my.some((r) => r.is_guest) && t('events.asGuest'), event.location_name]
                .filter(Boolean).join(' · ')}
            </p>
          </div>
          {event.type === 'match' && (event.own_logo_url || event.opponent_logo_url) && (
            <div className="flex shrink-0 items-center gap-1">
              {event.own_logo_url && <img src={event.own_logo_url} alt="" className="size-9 rounded object-contain" />}
              <span className="text-xs text-slate-400">{t('events.versus')}</span>
              {event.opponent_logo_url ? (
                <img src={event.opponent_logo_url} alt={event.opponent?.name ?? ''} className="size-9 rounded object-contain" />
              ) : (
                <span className="size-9" />
              )}
            </div>
          )}
        </div>

        {cancelled && <p className="mt-1 text-sm font-medium text-red-700">{t('events.cancelled')}</p>}
        {event.response_mode === 'info' && !cancelled && event.type !== 'helper' && <p className="mt-1 text-sm text-slate-600">{t('events.infoOnly')}</p>}
        {event.task_summary && !cancelled && (
          <p className="mt-1 text-xs text-slate-600">
            {t('tasks.summary', { count: event.task_summary.count, taken: event.task_summary.taken, slots: event.task_summary.slots })}
          </p>
        )}

        {event.response_mode === 'rsvp' && !cancelled && (
          <p className="mt-2 text-xs text-slate-600">
            <span className="text-green-700">✓ {event.counts.yes}</span>
            {event.max_participants ? ` / ${event.max_participants}` : ''} ·{' '}
            <span className="text-amber-700">? {event.counts.maybe}</span> ·{' '}
            <span className="text-red-700">✕ {event.counts.no}</span> · {t('events.open', { count: event.counts.open })}
            {highlight && <span className="ml-1 font-medium text-amber-800">· {t('events.belowMinimum', { min: event.min_participants })}</span>}
            {event.is_full && <span className="ml-1 font-medium">· {t('events.full')}</span>}
            {event.rsvp_deadline && !event.deadline_passed && (
              <span className="block">{t('events.deadlineUntil', { date: formatDateTime(event.rsvp_deadline) })}</span>
            )}
          </p>
        )}
      </Link>

      {event.response_mode === 'rsvp' && !cancelled && event.my.length > 0 && (
        <div className="mt-3 space-y-2">
          {event.my.map((reply) => (
            <RsvpControl key={reply.person_id} event={event} reply={reply} showName={event.my.length > 1 || !reply.is_self} />
          ))}
        </div>
      )}
    </article>
  );
}
