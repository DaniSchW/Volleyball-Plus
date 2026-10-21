import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { api, del, errorText, post, type RsvpRule, type RsvpRuleOptions, type RsvpStatus } from '../lib/api';
import { formatDate, formatDay, weekdayName } from '../lib/dates';
import { useTenantPath, useTenantSlug } from '../lib/queries';
import { Button, Card, Field, Loading, Notice, PageTitle, Select, Toggle } from '../components/ui';

const WEEKDAYS = [1, 2, 3, 4, 5, 6, 7];
const STATUSES: RsvpStatus[] = ['yes', 'maybe', 'no'];
type Result = { data: RsvpRule; applied: number; skipped: { event: { id: string; title: string; starts_at: string }; message: string }[] };

/**
 * Answer many events at once ("Daueranwort"): e.g. Wednesday trainings
 * yes, Friday trainings no. Applies to the upcoming events and to new ones;
 * single events can still be answered differently.
 */
export function RsvpRulesPage() {
  const { t } = useTranslation();
  const slug = useTenantSlug();
  const tp = useTenantPath();
  const queryClient = useQueryClient();
  const options = useQuery({ queryKey: ['rsvp-rules', slug], queryFn: async () => (await api<{ data: RsvpRuleOptions }>(tp('rsvp-rules'))).data });
  const [result, setResult] = useState<Result | null>(null);

  const remove = useMutation({
    mutationFn: (id: string) => del(tp(`rsvp-rules/${id}`)),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['rsvp-rules', slug] }),
  });

  if (options.isPending) return <Loading />;
  if (options.isError) return <Notice tone="error">{errorText(options.error, t('errors.generic'))}</Notice>;

  const persons = options.data.persons;
  const nameOf = (id: string) => persons.find((p) => p.id === id)?.name ?? '';

  return (
    <div className="space-y-4">
      <PageTitle>{t('rules.title')}</PageTitle>
      <p className="text-sm text-slate-600">{t('rules.intro')}</p>

      {result && (
        <Notice tone={result.skipped.length > 0 ? 'error' : 'success'}>
          <p>{t('rules.applied', { count: result.applied })}</p>
          {result.skipped.length > 0 && (
            <ul className="mt-1 list-disc pl-5 text-xs">
              {result.skipped.map((s) => <li key={s.event.id}>{formatDay(s.event.starts_at)} · {s.event.title}: {s.message}</li>)}
            </ul>
          )}
        </Notice>
      )}

      {persons.length === 0 ? (
        <Notice>{t('rules.noPersons')}</Notice>
      ) : (
        <RuleForm
          options={options.data}
          onSaved={(r) => {
            setResult(r);
            void queryClient.invalidateQueries({ queryKey: ['rsvp-rules', slug] });
            void queryClient.invalidateQueries({ queryKey: ['events'] });
            void queryClient.invalidateQueries({ queryKey: ['event'] });
          }}
        />
      )}

      <Card title={t('rules.active')}>
        {options.data.rules.length === 0 ? (
          <p className="text-sm text-slate-500">{t('rules.none')}</p>
        ) : (
          <ul className="divide-y divide-slate-100">
            {options.data.rules.map((rule) => (
              <li key={rule.id} className="flex items-start gap-3 py-2 text-sm">
                <span className={`mt-0.5 shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold ${STATUS_CLASS[rule.status]}`}>{t(`events.status.${rule.status}`)}</span>
                <span className="min-w-0 flex-1">
                  <span className="block">{describe(rule, t)}</span>
                  <span className="block text-xs text-slate-500">
                    {[persons.length > 1 && nameOf(rule.person_id), rule.reason].filter(Boolean).join(' · ')}
                  </span>
                </span>
                <button type="button" className="shrink-0 rounded px-2 text-red-700" aria-label={t('rules.delete')} onClick={() => remove.mutate(rule.id)}>✕</button>
              </li>
            ))}
          </ul>
        )}
        <p className="mt-2 text-xs text-slate-500">{t('rules.deleteHint')}</p>
      </Card>
    </div>
  );
}

const STATUS_CLASS: Record<RsvpStatus, string> = {
  yes: 'bg-emerald-100 text-emerald-900',
  maybe: 'bg-amber-100 text-amber-900',
  no: 'bg-red-100 text-red-900',
};

/** "Herren 1 · Training · Mi" or "Herren 1 · Training Mo 19:30–21:30" */
function describe(rule: RsvpRule, t: (key: string, o?: Record<string, unknown>) => string): string {
  const what = rule.series
    ? `${rule.series.title} (${rule.series.weekdays.map((d) => weekdayName(d, 'short')).join(', ')} ${rule.series.start_time})`
    : rule.event_type ? t(`events.types.${rule.event_type}`) : t('rules.allTypes');
  const days = !rule.series && rule.weekdays?.length ? rule.weekdays.map((d) => weekdayName(d, 'short')).join(', ') : null;
  const until = rule.valid_until ? t('rules.untilShort', { date: formatDate(rule.valid_until) }) : null;
  return [rule.team?.name ?? t('rules.allTeams'), what, days, until].filter(Boolean).join(' · ');
}

function RuleForm({ options, onSaved }: { options: RsvpRuleOptions; onSaved: (result: Result) => void }) {
  const { t } = useTranslation();
  const tp = useTenantPath();
  const [personId, setPersonId] = useState(options.persons[0].id);
  const person = options.persons.find((p) => p.id === personId) ?? options.persons[0];
  const [teamId, setTeamId] = useState(person.teams.length === 1 ? person.teams[0].id : '');
  const team = person.teams.find((x) => x.id === teamId);
  // "type:training" or "series:<id>" or "" (all)
  const [what, setWhat] = useState('type:training');
  const [weekdays, setWeekdays] = useState<number[]>([]);
  const [status, setStatus] = useState<RsvpStatus>('yes');
  const [reason, setReason] = useState('');
  const [overwrite, setOverwrite] = useState(true);
  // '' = no end, 'season' = end of the season, 'date' = own date
  const [end, setEnd] = useState<'' | 'season' | 'date'>('');
  const [endDate, setEndDate] = useState('');
  const validUntil = end === 'season' ? options.season_end : end === 'date' ? endDate || null : null;
  const series = what.startsWith('series:') ? team?.series.find((s) => `series:${s.id}` === what) : undefined;

  const save = useMutation({
    mutationFn: () => post<Result>(tp('rsvp-rules'), {
      person_id: person.id,
      status,
      reason: status === 'yes' ? null : reason || null,
      team_id: teamId || null,
      series_id: series?.id ?? null,
      event_type: what.startsWith('type:') ? what.slice(5) : null,
      weekdays: series ? [] : weekdays,
      valid_until: validUntil,
      overwrite,
    }),
    onSuccess: (result) => {
      setWeekdays([]);
      setReason('');
      onSaved(result);
    },
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    save.mutate();
  };

  return (
    <Card title={t('rules.new')}>
      <form onSubmit={submit} className="space-y-3">
        {options.persons.length > 1 && (
          <Select label={t('rules.person')} value={personId} onChange={(e) => { setPersonId(e.target.value); setTeamId(''); setWhat('type:training'); }}>
            {options.persons.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
          </Select>
        )}
        <Select label={t('rules.team')} value={teamId} onChange={(e) => { setTeamId(e.target.value); if (what.startsWith('series:')) setWhat('type:training'); }}>
          <option value="">{t('rules.allTeams')}</option>
          {person.teams.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
        </Select>
        <Select label={t('rules.what')} value={what} onChange={(e) => setWhat(e.target.value)}>
          <option value="type:training">{t('events.types.training')}</option>
          <option value="type:match">{t('events.types.match')}</option>
          <option value="type:extra">{t('events.types.extra')}</option>
          <option value="">{t('rules.allTypes')}</option>
          {team?.series.map((s) => (
            <option key={s.id} value={`series:${s.id}`}>
              {t('rules.series', { title: s.title, days: s.weekdays.map((d) => weekdayName(d, 'short')).join(', '), time: `${s.start_time}–${s.end_time}` })}
            </option>
          ))}
        </Select>
        {!series && (
          <fieldset>
            <legend className="mb-1 text-sm font-medium text-slate-700">{t('rules.weekdays')}</legend>
            <div className="flex flex-wrap gap-2">
              {WEEKDAYS.map((d) => {
                const on = weekdays.includes(d);
                return (
                  <button
                    key={d}
                    type="button"
                    aria-pressed={on}
                    className={`min-h-10 min-w-11 rounded-full border px-3 text-sm ${on ? 'border-brand-solid bg-brand-solid text-white' : 'border-slate-300 bg-surface text-slate-700'}`}
                    onClick={() => setWeekdays(on ? weekdays.filter((x) => x !== d) : [...weekdays, d].sort())}
                  >
                    {weekdayName(d, 'short')}
                  </button>
                );
              })}
            </div>
            <p className="mt-1 text-xs text-slate-500">{t('rules.weekdaysHint')}</p>
          </fieldset>
        )}
        <fieldset>
          <legend className="mb-1 text-sm font-medium text-slate-700">{t('rules.answer')}</legend>
          <div className="grid grid-cols-3 gap-2">
            {STATUSES.map((s) => (
              <button
                key={s}
                type="button"
                aria-pressed={status === s}
                className={`min-h-11 rounded-lg border text-sm font-medium ${status === s ? (s === 'yes' ? 'border-emerald-600 bg-emerald-600 text-white' : s === 'no' ? 'border-red-600 bg-red-600 text-white' : 'border-amber-500 bg-amber-500 text-white') : 'border-slate-300 bg-surface'}`}
                onClick={() => setStatus(s)}
              >
                {t(`events.status.${s}`)}
              </button>
            ))}
          </div>
        </fieldset>
        {status !== 'yes' && <Field label={t('rules.reason')} maxLength={255} value={reason} onChange={(e) => setReason(e.target.value)} hint={t('rules.reasonHint')} />}
        <Select label={t('rules.until')} value={end} onChange={(e) => setEnd(e.target.value as typeof end)}>
          <option value="">{t('rules.untilNone')}</option>
          <option value="season">{t('rules.untilSeason', { date: formatDate(options.season_end) })}</option>
          <option value="date">{t('rules.untilDate')}</option>
        </Select>
        {end === 'date' && (
          <Field label={t('rules.untilDateLabel')} type="date" required min={new Date().toISOString().slice(0, 10)} value={endDate} onChange={(e) => setEndDate(e.target.value)} />
        )}
        <Toggle label={t('rules.overwrite')} checked={overwrite} onChange={setOverwrite} />
        {save.isError && <Notice tone="error">{errorText(save.error, t('errors.generic'))}</Notice>}
        <Button type="submit" className="w-full" disabled={save.isPending}>{t('rules.save')}</Button>
      </form>
    </Card>
  );
}
