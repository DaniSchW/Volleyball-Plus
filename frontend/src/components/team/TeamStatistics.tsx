import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { api, type EventType, type HistoryRow, type Period, type StatisticsRow, type TaskStatsRow, type Team, type TeamStatistics as Stats } from '../../lib/api';
import { formatDate, formatDateTime, formatDay } from '../../lib/dates';
import { useTenantPath, useTenantSlug } from '../../lib/queries';
import { Card, Field, Loading, Notice } from '../ui';

const TYPES: EventType[] = ['training', 'match', 'extra'];
type Preset = 'season' | 'last_4_weeks' | 'custom';

function query(period: Period | null, types: EventType[]): string {
  const params = new URLSearchParams();
  if (period) {
    params.set('from', period.from);
    params.set('to', period.to);
  }
  types.forEach((type) => params.append('types[]', type));
  return params.toString();
}

/**
 * Reply rates of the team in a chosen period. Trainers and admins see a
 * comparison of all players; players only their own rate.
 */
export function TeamStatistics({ team }: { team: Team }) {
  const { t } = useTranslation();
  const tp = useTenantPath();
  const [preset, setPreset] = useState<Preset>('season');
  const [custom, setCustom] = useState<Period | null>(null);
  const [types, setTypes] = useState<EventType[]>(TYPES);

  // The server defaults to the season (its start is a department setting); its
  // first answer brings the other presets.
  const [presets, setPresets] = useState<Stats['presets'] | null>(null);
  const period = preset === 'custom' ? custom : preset === 'season' ? null : presets?.[preset] ?? null;
  const qs = query(period, types);

  const stats = useQuery({
    queryKey: ['statistics', team.id, qs],
    queryFn: async () => {
      const data = (await api<{ data: Stats }>(tp(`teams/${team.id}/statistics?${qs}`))).data;
      setPresets((p) => p ?? data.presets);
      return data;
    },
    placeholderData: (previous) => previous,
    enabled: preset !== 'custom' || !!custom,
  });

  const choose = (value: Preset) => {
    if (value === 'custom' && !custom) setCustom(stats.data?.period ?? null);
    setPreset(value);
  };
  const toggleType = (type: EventType) =>
    setTypes((current) => (current.includes(type) ? (current.length > 1 ? current.filter((x) => x !== type) : current) : [...current, type]));

  return (
    <div className="space-y-4">
      <div className="space-y-3">
        <div role="group" aria-label={t('stats.period')} className="flex flex-wrap gap-2">
          {(['last_4_weeks', 'season', 'custom'] as const).map((value) => (
            <Chip key={value} active={preset === value} onClick={() => choose(value)}>{t(`stats.presets.${value}`)}</Chip>
          ))}
        </div>
        {preset === 'custom' && custom && (
          <div className="grid grid-cols-2 gap-3">
            <Field label={t('stats.from')} type="date" value={custom.from} max={custom.to} onChange={(e) => e.target.value && setCustom({ ...custom, from: e.target.value })} />
            <Field label={t('stats.to')} type="date" value={custom.to} min={custom.from} onChange={(e) => e.target.value && setCustom({ ...custom, to: e.target.value })} />
          </div>
        )}
        <div role="group" aria-label={t('stats.types')} className="flex flex-wrap gap-2">
          {TYPES.map((type) => (
            <Chip key={type} active={types.includes(type)} onClick={() => toggleType(type)}>{t(`events.types.${type}`)}</Chip>
          ))}
        </div>
      </div>

      {stats.isPending && <Loading />}
      {stats.isError && <Notice tone="error">{t('errors.generic')}</Notice>}
      {stats.data && <Result team={team} stats={stats.data} qs={qs} />}
      {stats.data?.can_see_all && <NoReaction rows={stats.data.rows} />}
      {stats.data && <TaskStats team={team} qs={query(stats.data.period, [])} />}
    </div>
  );
}

function Chip({ active, onClick, children }: { active: boolean; onClick: () => void; children: React.ReactNode }) {
  return (
    <button
      type="button"
      aria-pressed={active}
      onClick={onClick}
      className={`min-h-10 rounded-full border px-4 text-sm ${active ? 'border-brand-700 bg-brand-700 text-white' : 'border-slate-300 bg-white text-slate-700'}`}
    >
      {children}
    </button>
  );
}

function Result({ team, stats, qs }: { team: Team; stats: Stats; qs: string }) {
  const { t } = useTranslation();
  const [open, setOpen] = useState<string | null>(null);

  const title = stats.can_see_all ? t('stats.comparison') : t('stats.own');

  return (
    <Card title={title}>
      <p className="mb-3 text-xs text-slate-500">
        {t('stats.summary', { from: formatDate(stats.period.from), to: formatDate(stats.period.to), count: stats.event_count })}
      </p>
      {stats.rows.length === 0 && (
        <p className="text-sm text-slate-500">{stats.event_count === 0 || stats.can_see_all ? t('stats.empty') : t('stats.onlyTrainers')}</p>
      )}
      <ul className="space-y-3">
        {stats.rows.map((row) => (
          <li key={row.person.id}>
            <button
              type="button"
              className="block w-full text-left"
              aria-expanded={open === row.person.id}
              onClick={() => setOpen(open === row.person.id ? null : row.person.id)}
            >
              <RateBar row={row} />
            </button>
            {open === row.person.id && <History team={team} personId={row.person.id} qs={qs} />}
          </li>
        ))}
      </ul>
      {stats.rows.length > 0 && <p className="mt-4 text-xs text-slate-500">{t('stats.explain')}</p>}
    </Card>
  );
}

/** One horizontal bar: share of accepted invitations; the numbers are written out next to it. */
function RateBar({ row }: { row: StatisticsRow }) {
  const { t } = useTranslation();
  const details = [
    t('stats.accepted', { yes: row.yes, invited: row.invited }),
    row.maybe > 0 && `? ${row.maybe}`,
    row.no > 0 && `✕ ${row.no}`,
    row.late > 0 && t('stats.late', { count: row.late }),
  ].filter(Boolean).join(' · ');
  const marker = row.is_guest ? t('events.guest') : !row.is_current ? t('stats.former') : null;

  return (
    <span className="block" title={`${row.person.name}: ${row.rate} % – ${details}`}>
      <span className="flex items-baseline justify-between gap-2 text-sm">
        <span className="min-w-0 truncate font-medium">
          {row.person.name}
          {marker && <span className="ml-1 text-xs font-normal text-slate-500">({marker})</span>}
        </span>
        <span className="shrink-0 tabular-nums text-slate-700">{row.rate} %</span>
      </span>
      <span className="mt-1 block h-3 w-full overflow-hidden rounded bg-slate-100" aria-hidden="true">
        <span className="block h-full rounded bg-brand-600" style={{ width: `${row.rate}%` }} />
      </span>
      <span className="mt-0.5 block text-xs text-slate-500">
        {details}
        {row.no_reaction > 0 && <span className="font-medium text-red-700"> · {t('stats.noReaction', { count: row.no_reaction })}</span>}
      </span>
      {row.attendance_rate !== null && (
        <span className="mt-1 flex items-center gap-2 text-xs text-slate-600">
          <span className="block h-2 flex-1 overflow-hidden rounded bg-slate-100" aria-hidden="true">
            <span className="block h-full rounded bg-emerald-500" style={{ width: `${row.attendance_rate}%` }} />
          </span>
          <span className="shrink-0 tabular-nums">{t('stats.attendance', { rate: row.attendance_rate, present: row.trainings_present, count: row.trainings })}</span>
        </span>
      )}
    </span>
  );
}

/** Who did not react at all (past events without any answer) – for trainers and admins. */
function NoReaction({ rows }: { rows: StatisticsRow[] }) {
  const { t } = useTranslation();
  const list = rows.filter((row) => row.no_reaction > 0).sort((a, b) => b.no_reaction - a.no_reaction);

  return (
    <Card title={t('stats.noReactionTitle')}>
      <p className="mb-2 text-xs text-slate-500">{t('stats.noReactionHint')}</p>
      {list.length === 0 ? (
        <p className="text-sm text-slate-500">{t('stats.noReactionNone')}</p>
      ) : (
        <ul className="divide-y divide-slate-100">
          {list.map((row) => (
            <li key={row.person.id} className="flex items-baseline justify-between gap-3 py-2 text-sm">
              <span className="min-w-0 truncate">{row.person.name}</span>
              <span className="shrink-0 rounded-full bg-red-50 px-2 py-0.5 text-xs font-semibold text-red-800">
                {t('stats.noReactionOf', { count: row.no_reaction, invited: row.invited })}
              </span>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}

/** Tasks and helper services taken per person (same visibility as the rates). */
function TaskStats({ team, qs }: { team: Team; qs: string }) {
  const { t } = useTranslation();
  const tp = useTenantPath();
  const stats = useQuery({
    queryKey: ['task-statistics', team.id, qs],
    queryFn: async () => (await api<{ data: { can_see_all: boolean; rows: TaskStatsRow[] } }>(tp(`teams/${team.id}/statistics/tasks?${qs}`))).data,
  });

  if (!stats.data || (stats.data.rows.length === 0 && !stats.data.can_see_all)) return null;

  return (
    <Card title={stats.data.can_see_all ? t('stats.tasksAll') : t('stats.tasksOwn')}>
      {stats.data.rows.length === 0 && <p className="text-sm text-slate-500">{t('stats.tasksNone')}</p>}
      <ul className="divide-y divide-slate-100">
        {stats.data.rows.map((row) => (
          <li key={row.person.id} className="flex items-baseline justify-between gap-3 py-2 text-sm">
            <span className="min-w-0 truncate">{row.person.name}</span>
            <span className="shrink-0 text-slate-700">{t('stats.tasksCount', { helper: row.helper, other: row.other })}</span>
          </li>
        ))}
      </ul>
    </Card>
  );
}

const STATUS_ICON = { yes: '✓', maybe: '?', no: '✕' } as const;

function History({ team, personId, qs }: { team: Team; personId: string; qs: string }) {
  const { t } = useTranslation();
  const slug = useTenantSlug();
  const tp = useTenantPath();
  const history = useQuery({
    queryKey: ['statistics-history', team.id, personId, qs],
    queryFn: async () => (await api<{ data: HistoryRow[] }>(tp(`teams/${team.id}/statistics/persons/${personId}?${qs}`))).data,
  });

  if (history.isPending) return <Loading />;

  return (
    <ul className="mt-2 divide-y divide-slate-100 rounded-lg bg-slate-50 px-3 text-sm">
      {history.data?.map((row) => (
        <li key={row.event.id} className="flex items-center justify-between gap-3 py-2">
          <Link to={`/${slug}/events/${row.event.id}`} className="min-w-0">
            <span className="block truncate">{formatDay(row.event.starts_at)} · {row.event.title}</span>
            <span className="block text-xs text-slate-500">
              {row.responded_at ? t('stats.answeredAt', { date: formatDateTime(row.responded_at) }) : t('stats.noAnswer')}
              {row.after_deadline && ` · ${t('stats.afterDeadline')}`}
              {row.attended !== null && (
                <span className={row.attended ? 'text-emerald-700' : 'text-slate-600'}>
                  {' · '}{row.attended ? t('stats.present') : t('stats.absent')}{row.attendance_recorded ? '' : ` (${t('stats.byReply')})`}
                </span>
              )}
            </span>
          </Link>
          <span className={`shrink-0 text-xs ${row.status ? '' : 'font-medium text-red-700'}`}>
            {row.status ? `${STATUS_ICON[row.status]} ${t(`events.status.${row.status}`)}` : t('stats.noReactionShort')}
          </span>
        </li>
      ))}
    </ul>
  );
}
