import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useParams } from 'react-router';
import { api, errorText, post, POSITIONS, type EventDetail, type ResponseRow, type RsvpStatus } from '../lib/api';
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

  if (!event.responses) {
    return (
      <Card title={t('events.replies')}>
        <p className="text-sm">
          {t('events.status.yes')}: {event.counts.yes} · {t('events.status.maybe')}: {event.counts.maybe} · {t('events.status.no')}: {event.counts.no} · {t('events.open', { count: event.counts.open })}
        </p>
      </Card>
    );
  }

  const byGroup = (group: RsvpStatus | 'open'): ResponseRow[] =>
    event.responses!.filter((row) => (row.status ?? 'open') === group);

  return (
    <Card title={t('events.replies')}>
      <div className="space-y-4">
        {GROUPS.map((group) => {
          const rows = byGroup(group);
          if (rows.length === 0) return null;
          return (
            <div key={group}>
              <h3 className="mb-1 text-sm font-semibold text-slate-600">
                {group === 'open' ? t('events.notAnswered') : t(`events.status.${group}`)} ({rows.length})
              </h3>
              <ul className="divide-y divide-slate-100">
                {rows.map((row) => (
                  <li key={row.person.id} className="flex items-center gap-3 py-2">
                    <Avatar url={row.person.avatar_url} name={row.person.name} size={32} />
                    <span className="min-w-0 flex-1">
                      <span className="block truncate text-sm">{row.person.name}</span>
                      <span className="block truncate text-xs text-slate-500">
                        {[row.person.is_guest && t('events.guest'), row.person.role !== 'player' && !row.person.is_guest && t(`roles.${row.person.role}`), row.person.primary_position && t(`positions.${row.person.primary_position}`), row.reason]
                          .filter(Boolean).join(' · ')}
                      </span>
                    </span>
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
