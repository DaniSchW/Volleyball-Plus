import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useParams } from 'react-router';
import { api, errorText, post, put, POSITIONS, type EventDetail, type Position, type ResponseRow, type RsvpStatus } from '../lib/api';
import { formatDateTime, formatLongDay, formatTime } from '../lib/dates';
import { useTenantPath, useTenantSlug } from '../lib/queries';
import { RsvpControl } from '../components/events/RsvpControl';
import { TasksCard } from '../components/events/TasksCard';
import { TypeIcon } from '../components/events/EventCard';
import { Avatar, Button, Card, Loading, Notice } from '../components/ui';

export function EventPage() {
  const { t } = useTranslation();
  const { eventId = '' } = useParams();
  const slug = useTenantSlug();
  const tp = useTenantPath();
  const navigate = useNavigate();
  const queryClient = useQueryClient();

  const event = useQuery({ queryKey: ['event', eventId], queryFn: async () => (await api<{ data: EventDetail }>(tp(`events/${eventId}`))).data });

  const cancel = useMutation({
    mutationFn: (restore: boolean) => post<{ data: EventDetail }>(`${tp(`events/${eventId}/cancel`)}${restore ? '?restore=1' : ''}`),
    onSuccess: ({ data }) => {
      queryClient.setQueryData(['event', eventId], data);
      void queryClient.invalidateQueries({ queryKey: ['events'] });
    },
  });
  const endSeries = useMutation({
    mutationFn: () => post(tp(`events/${eventId}/end-series`)),
    onSuccess: async () => {
      await queryClient.invalidateQueries();
      navigate(`/${slug}`);
    },
  });

  if (event.isPending) return <Loading />;
  if (event.isError) return <Notice tone="error">{errorText(event.error, t('errors.generic'))}</Notice>;

  const e = event.data;
  const cancelled = e.status === 'cancelled';

  return (
    <div className="space-y-4">
      <div>
        <p className="text-sm text-slate-500">
          {e.teams.map((team) => team.name).join(', ')}
          {e.training_group && ` · ${e.training_group.name}`}
        </p>
        <h1 className={`flex items-center gap-2 text-xl font-semibold ${cancelled ? 'line-through' : ''}`}>
          <TypeIcon type={e.type} />
          {e.title}
        </h1>
        {cancelled && <p className="font-medium text-red-700">{t('events.cancelled')}</p>}
      </div>

      {e.type === 'match' && (
        <Card>
          <div className="flex items-center justify-around gap-4 text-center">
            <div className="flex flex-col items-center gap-1">
              {e.own_logo_url ? <img src={e.own_logo_url} alt="" className="size-16 object-contain" /> : <span className="size-16" />}
              <span className="text-sm">{e.teams[0]?.name}</span>
            </div>
            <span className="text-slate-400">{t('events.versus')}</span>
            <div className="flex flex-col items-center gap-1">
              {e.opponent_logo_url ? <img src={e.opponent_logo_url} alt="" className="size-16 object-contain" /> : <span className="size-16" />}
              <span className="text-sm">{e.opponent?.name ?? '–'}</span>
            </div>
          </div>
          {e.is_home !== null && <p className="mt-2 text-center text-xs text-slate-500">{e.is_home ? t('events.home') : t('events.away')}</p>}
        </Card>
      )}

      <Card>
        <dl className="space-y-2 text-sm">
          <div>
            <dt className="sr-only">{t('events.when')}</dt>
            <dd className="font-medium">{formatLongDay(e.starts_at)}, {formatTime(e.starts_at)}–{formatTime(e.ends_at)}</dd>
          </div>
          {e.meet_at && <div><dt className="inline text-slate-500">{t('events.meetAt')}: </dt><dd className="inline">{formatTime(e.meet_at)}</dd></div>}
          {e.rsvp_deadline && <div><dt className="inline text-slate-500">{t('events.deadline')}: </dt><dd className="inline">{formatDateTime(e.rsvp_deadline)}</dd></div>}
          {(e.location_name || e.address) && (
            <div>
              <dt className="sr-only">{t('events.where')}</dt>
              <dd>{e.location_name}{e.location_name && e.address ? ', ' : ''}{e.address}</dd>
            </div>
          )}
        </dl>
        {e.directions_url && (
          <a href={e.directions_url} target="_blank" rel="noopener noreferrer" className="mt-3 inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-4 text-sm font-medium">
            🧭 {t('events.directions')}
          </a>
        )}
        {e.description && <p className="mt-3 whitespace-pre-line text-sm text-slate-700">{e.description}</p>}
      </Card>

      {e.response_mode === 'rsvp' && !cancelled && e.my.length > 0 && (
        <Card title={t('events.yourReply')}>
          <div className="space-y-3">
            {e.my.map((reply) => <RsvpControl key={reply.person_id} event={e} reply={reply} showName={e.my.length > 1 || !reply.is_self} />)}
          </div>
        </Card>
      )}

      {!cancelled && <TasksCard event={e} />}

      {e.position_limits && Object.keys(e.position_limits).length > 0 && <PositionLimits event={e} limits={e.position_limits} />}
      {e.guests_positions_only && !(e.position_limits && Object.keys(e.position_limits).length > 0) && e.guest_position_needs && (
        <PositionLimits event={e} limits={e.guest_position_needs} ideal />
      )}
      {e.guests_positions_only && <p className="text-xs text-slate-500">{t('events.guestsPositionsOnlyInfo')}</p>}

      {e.positions && <Positions positions={e.positions} />}

      {e.response_mode === 'rsvp' && <Responses event={e} />}

      {e.can_manage && (
        <Card title={t('events.manage')}>
          <div className="flex flex-wrap gap-2">
            <Link to={`/${slug}/events/${e.id}/edit`} className="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-4 text-sm font-medium">{t('events.edit')}</Link>
            {e.series_id && (
              <Link to={`/${slug}/events/${e.id}/edit?series=1`} className="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-4 text-sm font-medium">{t('events.editSeries')}</Link>
            )}
            <Button variant={cancelled ? 'secondary' : 'danger'} onClick={() => cancel.mutate(cancelled)}>
              {cancelled ? t('events.restore') : t('events.cancel')}
            </Button>
            {e.series_id && (
              <Button variant="danger" onClick={() => window.confirm(t('events.endSeriesConfirm')) && endSeries.mutate()}>{t('events.endSeries')}</Button>
            )}
          </div>
        </Card>
      )}
    </div>
  );
}

/** Positions-Soll (or the team's ideal squad): places taken per position, for everybody invited. */
function PositionLimits({ event, limits, ideal = false }: { event: EventDetail; limits: Partial<Record<Position, number>>; ideal?: boolean }) {
  const { t } = useTranslation();
  // In the ideal squad 0 just means "not wanted" – nothing to show for guests.
  const keys = POSITIONS.filter((p) => limits[p] !== undefined && !(ideal && limits[p] === 0));

  return (
    <Card title={ideal ? t('events.idealSquad') : t('events.positionLimits')}>
      <ul className="flex flex-wrap gap-2">
        {keys.map((key) => {
          const taken = event.position_taken?.[key] ?? 0;
          const limit = limits[key]!;
          return (
            <li key={key} className={`rounded-full px-3 py-1 text-sm ${taken >= limit ? 'bg-red-50 text-red-800' : 'bg-slate-100'}`}>
              {t(`positions.${key}`)}: <span className="font-semibold">{taken}/{limit}</span>
              {taken >= limit && <span className="sr-only"> {t('events.positionFull')}</span>}
            </li>
          );
        })}
      </ul>
      <p className="mt-2 text-xs text-slate-500">{ideal ? t('events.idealSquadInfo') : t('events.positionLimitsInfo')}</p>
    </Card>
  );
}

/** Accepted players per position, so the trainer can plan the training. */
function Positions({ positions }: { positions: NonNullable<EventDetail['positions']> }) {
  const { t } = useTranslation();
  const keys = [...POSITIONS, 'unknown' as const].filter((key) => positions[key]);
  if (keys.length === 0) return null;

  return (
    <Card title={t('events.positions')}>
      <ul className="flex flex-wrap gap-2">
        {keys.map((key) => (
          <li key={key} className="rounded-full bg-slate-100 px-3 py-1 text-sm">
            {key === 'unknown' ? t('events.positionUnknown') : t(`positions.${key}`)}: <span className="font-semibold">{positions[key]}</span>
          </li>
        ))}
      </ul>
    </Card>
  );
}

const GROUPS: (RsvpStatus | 'open')[] = ['yes', 'maybe', 'no', 'open'];

function Responses({ event }: { event: EventDetail }) {
  const { t } = useTranslation();
  const [correcting, setCorrecting] = useState(false);

  if (!event.responses) {
    return (
      <Card title={t('events.replies')}>
        <p className="text-sm">
          {t('events.status.yes')}: {event.counts.yes} · {t('events.status.maybe')}: {event.counts.maybe} · {t('events.status.no')}: {event.counts.no} · {t('events.open', { count: event.counts.open })}
        </p>
      </Card>
    );
  }

  // Accepted players sorted by position, so the trainer sees the line-up at a glance.
  const order = (row: ResponseRow) => {
    const position = shownPosition(row);
    return position ? POSITIONS.indexOf(position) : POSITIONS.length;
  };
  const byGroup = (group: RsvpStatus | 'open'): ResponseRow[] => {
    const rows = event.responses!.filter((row) => (row.status ?? 'open') === group);
    return group === 'yes' ? [...rows].sort((a, b) => order(a) - order(b)) : rows;
  };

  if (correcting) return <CorrectReplies event={event} onDone={() => setCorrecting(false)} />;

  return (
    <Card
      title={t('events.replies')}
      actions={event.can_record_attendance ? (
        <button type="button" className="rounded px-2 py-1 text-sm text-brand-700" onClick={() => setCorrecting(true)}>{t('events.correct')}</button>
      ) : undefined}
    >
      {event.can_record_attendance && (
        <p className="mb-3 text-xs text-slate-500">
          {event.attendance_recorded_at ? t('events.attendanceRecordedAt', { date: formatDateTime(event.attendance_recorded_at) }) : t('events.attendanceNotRecorded')}
        </p>
      )}
      <div className="space-y-4">
        {GROUPS.map((group) => {
          const rows = byGroup(group);
          if (rows.length === 0) return null;
          return (
            <div key={group}>
              <h3 className={`mb-1 text-sm font-semibold ${group === 'open' && event.deadline_passed ? 'text-red-700' : 'text-slate-600'}`}>
                {group === 'open' ? (event.deadline_passed ? `⚠️ ${t('events.noReaction')}` : t('events.notAnswered')) : t(`events.status.${group}`)} ({rows.length})
              </h3>
              <ul className="divide-y divide-slate-100">
                {rows.map((row) => (
                  <li key={row.person.id} className="flex items-center gap-3 py-2">
                    <Avatar url={row.person.avatar_url} name={row.person.name} size={32} />
                    <span className="min-w-0 flex-1">
                      <span className="block truncate text-sm">{row.person.name}</span>
                      <span className="block truncate text-xs text-slate-500">
                        {[row.person.is_guest && t('events.guest'), row.person.role !== 'player' && !row.person.is_guest && t(`roles.${row.person.role}`), shownPosition(row) && t(`positions.${shownPosition(row)}`), row.reason]
                          .filter(Boolean).join(' · ')}
                      </span>
                    </span>
                    {row.attended !== null && (
                      <span className={`shrink-0 text-xs font-medium ${row.attended ? 'text-emerald-700' : 'text-slate-400'}`}>{row.attended ? t('events.present') : t('events.absent')}</span>
                    )}
                    {row.person.role === 'player' && <PositionBadges row={row} />}
                  </li>
                ))}
              </ul>
            </div>
          );
        })}
      </div>
    </Card>
  );
}

/** The position a player counts for: the accepted one, else the main position. */
const shownPosition = (row: ResponseRow): Position | null =>
  (row.status === 'yes' && row.as_position) || row.person.primary_position || row.person.positions?.[0] || null;

const BADGE_COLORS: Record<Position, string> = {
  setter: 'bg-amber-100 text-amber-900 ring-amber-300',
  opposite: 'bg-rose-100 text-rose-900 ring-rose-300',
  middle: 'bg-sky-100 text-sky-900 ring-sky-300',
  outside: 'bg-emerald-100 text-emerald-900 ring-emerald-300',
  libero: 'bg-violet-100 text-violet-900 ring-violet-300',
  universal: 'bg-slate-100 text-slate-800 ring-slate-300',
};

/** Short position badges next to the name: the counted position bold, further ones light. */
function PositionBadges({ row }: { row: ResponseRow }) {
  const { t } = useTranslation();
  const main = shownPosition(row);
  if (!main) return null;
  const others = (row.person.positions ?? []).filter((p) => p !== main);

  return (
    <span className="flex shrink-0 items-center gap-1">
      <span title={t(`positions.${main}`)} className={`rounded-md px-2 py-0.5 text-xs font-bold ring-1 ${BADGE_COLORS[main]}`}>{t(`positionsShort.${main}`)}</span>
      {others.map((p) => (
        <span key={p} title={t(`positions.${p}`)} className="rounded-md px-1.5 py-0.5 text-[0.65rem] font-medium text-slate-500 ring-1 ring-slate-200">{t(`positionsShort.${p}`)}</span>
      ))}
    </span>
  );
}

/**
 * Afterwards (trainers/admins): correct replies as they really were and
 * record who was there – both count for the statistics.
 */
function CorrectReplies({ event, onDone }: { event: EventDetail; onDone: () => void }) {
  const { t } = useTranslation();
  const tp = useTenantPath();
  const queryClient = useQueryClient();
  const rows = [...(event.responses ?? [])].sort((a, b) => a.person.name.localeCompare(b.person.name));
  const [present, setPresent] = useState<Set<string>>(() => new Set(rows.filter((r) => r.attended).map((r) => r.person.id)));
  const done = ({ data }: { data: EventDetail }) => {
    queryClient.setQueryData(['event', event.id], data);
    void queryClient.invalidateQueries({ queryKey: ['statistics'] });
  };

  const reply = useMutation({
    mutationFn: ({ personId, status }: { personId: string; status: RsvpStatus }) =>
      post<{ data: EventDetail }>(tp(`events/${event.id}/responses`), { person_id: personId, status }),
    onSuccess: done,
  });
  const save = useMutation({
    mutationFn: () => put<{ data: EventDetail }>(tp(`events/${event.id}/attendance`), { present: [...present] }),
    onSuccess: (result) => {
      done(result);
      onDone();
    },
  });

  return (
    <Card title={t('events.correctTitle')}>
      <p className="mb-3 text-xs text-slate-500">{t('events.correctHint')}</p>
      <ul className="divide-y divide-slate-100">
        {rows.map((row) => (
          <li key={row.person.id} className="flex flex-wrap items-center gap-2 py-2">
            <label className="flex min-h-11 min-w-0 flex-1 items-center gap-3 text-sm">
              <input
                type="checkbox"
                className="size-5 shrink-0 accent-emerald-600"
                checked={present.has(row.person.id)}
                onChange={(e) => setPresent((current) => {
                  const next = new Set(current);
                  if (e.target.checked) next.add(row.person.id);
                  else next.delete(row.person.id);
                  return next;
                })}
              />
              <span className="min-w-0 truncate">{row.person.name}</span>
            </label>
            <select
              aria-label={t('events.replyOf', { name: row.person.name })}
              className="min-h-10 rounded-lg border border-slate-300 bg-white px-2 text-sm"
              value={row.status ?? ''}
              disabled={reply.isPending}
              onChange={(e) => e.target.value && reply.mutate({ personId: row.person.id, status: e.target.value as RsvpStatus })}
            >
              {!row.status && <option value="">{t('events.noReaction')}</option>}
              {(['yes', 'maybe', 'no'] as const).map((status) => <option key={status} value={status}>{t(`events.status.${status}`)}</option>)}
            </select>
          </li>
        ))}
      </ul>
      {(reply.isError || save.isError) && <Notice tone="error">{errorText(reply.error ?? save.error, t('errors.generic'))}</Notice>}
      <div className="mt-3 flex flex-wrap gap-2">
        <Button onClick={() => save.mutate()} disabled={save.isPending}>{t('events.saveAttendance')}</Button>
        <Button variant="secondary" onClick={onDone}>{t('events.correctDone')}</Button>
      </div>
    </Card>
  );
}
