import { useEffect, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { useNavigate, useParams, useSearchParams } from 'react-router';
import { api, errorText, patch, post, POSITIONS, type EventDetail, type EventType, type Opponent, type Position, type TaskTemplateList, type Team, type TrainingGroup } from '../lib/api';
import { addMinutes, formatDay, formatTime, toLocalInput } from '../lib/dates';
import { HallSuggestions } from '../components/events/HallSuggestions';
import { TaskRows, type TaskRow } from '../components/tasks/TaskTemplates';
import { useTenantMe, useTenantPath, useTenantSlug } from '../lib/queries';
import { Button, Card, ChoiceDialog, Field, Loading, Notice, PageTitle, SaveBar, Select, Toggle } from '../components/ui';

const WEEKDAYS = [1, 2, 3, 4, 5, 6, 7];

interface FormState {
  type: EventType;
  title: string;
  description: string;
  allTeams: boolean;
  team_ids: string[];
  response_mode: 'rsvp' | 'info';
  starts_at: string;
  ends_at: string;
  meet_at: string;
  /** Reply deadline in hours before the start (empty = until the start). */
  deadline_hours: string;
  location_name: string;
  address: string;
  max_participants: string;
  position_limits: Partial<Record<Position, string>>;
  opponent_id: string;
  is_home: '' | 'home' | 'away';
  training_group_id: string;
  guests_allowed: boolean;
  guests_positions_only: boolean;
  min_participants: string;
  repeat: boolean;
  weekdays: number[];
  interval_weeks: number;
  ends_on: string;
}

/** Start (local input) minus hours → deadline as local input; null without hours. */
function deadlineFor(startsAt: string, hours: string): string | null {
  if (hours === '' || !startsAt) return null;
  const start = new Date(startsAt);
  if (Number.isNaN(start.getTime())) return null;
  return toLocalInput(new Date(start.getTime() - Number(hours) * 3600000).toISOString());
}

function initial(teamId?: string): FormState {
  const start = new Date();
  start.setDate(start.getDate() + 1);
  start.setHours(19, 30, 0, 0);
  const startLocal = toLocalInput(start.toISOString());
  return {
    type: 'training', title: '', description: '', allTeams: false, team_ids: teamId ? [teamId] : [], response_mode: 'rsvp',
    starts_at: startLocal, ends_at: addMinutes(startLocal, 120), meet_at: '', deadline_hours: '',
    location_name: '', address: '', max_participants: '', position_limits: {}, opponent_id: '', is_home: '', training_group_id: '', guests_allowed: true, guests_positions_only: false, min_participants: '',
    repeat: false, weekdays: [((start.getDay() + 6) % 7) + 1], interval_weeks: 1, ends_on: '',
  };
}

function fromEvent(e: EventDetail): FormState {
  return {
    ...initial(),
    type: e.type, title: e.title, description: e.description ?? '', allTeams: e.scope === 'tenant', team_ids: e.team_ids,
    response_mode: e.response_mode, starts_at: toLocalInput(e.starts_at), ends_at: toLocalInput(e.ends_at),
    meet_at: toLocalInput(e.meet_at),
    deadline_hours: e.rsvp_deadline ? String(Math.round((new Date(e.starts_at).getTime() - new Date(e.rsvp_deadline).getTime()) / 3600000)) : '',
    location_name: e.location_name ?? '',
    address: e.address ?? '', max_participants: e.max_participants?.toString() ?? '',
    position_limits: Object.fromEntries(Object.entries(e.position_limits ?? {}).map(([k, v]) => [k, String(v)])), opponent_id: e.opponent?.id ?? '',
    is_home: e.is_home === null ? '' : e.is_home ? 'home' : 'away',
    training_group_id: e.training_group?.id ?? '', guests_allowed: e.guests_allowed, guests_positions_only: e.guests_positions_only,
    min_participants: e.own_min_participants?.toString() ?? '',
    ...(e.series ? { weekdays: e.series.weekdays, interval_weeks: e.series.interval_weeks, ends_on: e.series.ends_on ?? '' } : {}),
  };
}

/** Create or edit an event; with ?series=1 the whole training series. */
export function EventFormPage() {
  const { t } = useTranslation();
  const { eventId } = useParams();
  const [params] = useSearchParams();
  const editSeries = params.get('series') === '1';
  const slug = useTenantSlug();
  const tp = useTenantPath();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const me = useTenantMe();

  const teams = useQuery({ queryKey: ['teams', slug], queryFn: async () => (await api<{ data: Team[] }>(tp('teams'))).data });
  const opponents = useQuery({ queryKey: ['opponents', slug], queryFn: async () => (await api<{ data: Opponent[] }>(tp('opponents'))).data });
  const existing = useQuery({
    queryKey: ['event', eventId],
    queryFn: async () => (await api<{ data: EventDetail }>(tp(`events/${eventId}`))).data,
    enabled: !!eventId,
  });

  const [form, setForm] = useState<FormState>(() => initial(params.get('team') ?? undefined));
  useEffect(() => {
    if (existing.data) setForm({ ...fromEvent(existing.data), repeat: editSeries });
  }, [existing.data, editSeries]);
  const set = <K extends keyof FormState>(key: K, value: FormState[K]) => setForm((f) => ({ ...f, [key]: value }));

  const manageable = (teams.data ?? []).filter((team) => team.can_invite);
  // New events can take over a task template ("Spieltag Damen 1").
  const [taskTemplate, setTaskTemplate] = useState('');
  // …and tasks entered by hand (e.g. referee duty on a tournament day), players put their names down in the event.
  const [taskRows, setTaskRows] = useState<TaskRow[]>([]);
  const templates = useQuery({
    queryKey: ['task-templates', slug],
    queryFn: async () => (await api<{ data: TaskTemplateList }>(tp('task-templates'))).data,
    enabled: !eventId,
  });

  const body = () => {
      const body: Record<string, unknown> = {
        type: form.type,
        title: form.title || null,
        description: form.description || null,
        starts_at: form.starts_at,
        ends_at: form.ends_at,
        meet_at: form.meet_at || null,
        rsvp_deadline: deadlineFor(form.starts_at, form.deadline_hours),
        location_name: form.location_name || null,
        address: form.address || null,
        max_participants: form.max_participants ? Number(form.max_participants) : null,
        response_mode: form.type === 'extra' ? form.response_mode : form.type === 'helper' ? 'info' : 'rsvp',
        position_limits: Object.fromEntries(POSITIONS.filter((p) => form.position_limits[p]).map((p) => [p, Number(form.position_limits[p])])),
      };
      if (form.type === 'training') {
        body.training_group_id = form.training_group_id || null;
        body.guests_allowed = form.guests_allowed;
        body.guests_positions_only = form.guests_allowed && form.guests_positions_only;
        body.min_participants = form.min_participants ? Number(form.min_participants) : null;
      }
      if (form.type === 'match') {
        body.opponent_id = form.opponent_id || null;
        body.is_home = form.is_home === '' ? null : form.is_home === 'home';
      }
      if (!eventId) {
        body.task_template_id = taskTemplate || null;
        const tasks = taskRows.filter((r) => r.title.trim()).map((r) => ({ title: r.title.trim(), slots: r.slots ? Number(r.slots) : null }));
        if (tasks.length > 0 && !form.repeat) body.tasks = tasks;
        body.scope = form.allTeams ? 'tenant' : 'team';
        body.team_ids = form.allTeams ? [] : form.team_ids;
      }
      if (form.repeat) body.repeat = { weekdays: form.weekdays, interval_weeks: form.interval_weeks, ends_on: form.ends_on || null };

      return body;
  };

  // Changing a series: first ask the server how many dates would drop out (shorter series, fewer weekdays).
  const [dropped, setDropped] = useState(0);
  const preview = useMutation({
    mutationFn: () => patch<{ data: { dropped: number } }>(`${tp(`events/${eventId}`)}?series=1&preview=1`, body()),
    onSuccess: ({ data }) => (data.dropped > 0 ? setDropped(data.dropped) : save.mutate(undefined)),
  });

  const save = useMutation({
    mutationFn: (droppedMode: 'cancel' | 'delete' | undefined) =>
      eventId
        ? patch<{ data: EventDetail }>(`${tp(`events/${eventId}`)}${editSeries ? '?series=1' : ''}`, { ...body(), ...(droppedMode ? { dropped: droppedMode } : {}) })
        : post<{ data: EventDetail }>(tp('events'), body()),
    onSuccess: async ({ data }) => {
      await queryClient.invalidateQueries({ queryKey: ['events'] });
      queryClient.setQueryData(['event', data.id], data);
      navigate(`/${slug}/events/${data.id}`, { replace: true });
    },
  });

  if (teams.isPending || (eventId && existing.isPending)) return <Loading />;

  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (eventId && editSeries) preview.mutate();
    else save.mutate(undefined);
  };

  const singleTeam = !form.allTeams && form.team_ids.length === 1 ? form.team_ids[0] : null;
  const ideal = teams.data?.find((team) => team.id === singleTeam)?.ideal_positions ?? null;

  const toggleTeam = (id: string, on: boolean) => set('team_ids', on ? [...form.team_ids, id] : form.team_ids.filter((x) => x !== id));
  const canRepeat = form.type === 'training' && !form.allTeams && (eventId ? editSeries : true);

  return (
    <form onSubmit={submit} className="space-y-4">
      <PageTitle>{eventId ? (editSeries ? t('events.editSeries') : t('events.edit')) : t('events.create')}</PageTitle>

      <Card>
        <div className="space-y-3">
          <Select label={t('events.type')} value={form.type} disabled={!!eventId && editSeries} onChange={(e) => set('type', e.target.value as EventType)}>
            {(['training', 'match', 'extra', 'helper'] as const).map((type) => <option key={type} value={type}>{t(`events.types.${type}`)}</option>)}
          </Select>
          <Field
            label={t('events.title')}
            hint={form.type === 'helper' ? t('events.helperHint') : form.type === 'extra' ? undefined : t('events.titleHint')}
            required={form.type === 'extra' || form.type === 'helper'}
            maxLength={120}
            value={form.title}
            onChange={(e) => set('title', e.target.value)}
          />
          {form.type === 'extra' && (
            <Select label={t('events.responseMode')} value={form.response_mode} onChange={(e) => set('response_mode', e.target.value as 'rsvp' | 'info')}>
              <option value="rsvp">{t('events.modes.rsvp')}</option>
              <option value="info">{t('events.modes.info')}</option>
            </Select>
          )}
        </div>
      </Card>

      {!eventId && (
        <Card title={t('events.teams')}>
          {me.data?.is_admin && (
            <Toggle label={t('events.allTeams')} checked={form.allTeams} onChange={(v) => set('allTeams', v)} />
          )}
          {!form.allTeams && manageable.map((team) => (
            <Toggle key={team.id} label={team.name} checked={form.team_ids.includes(team.id)} onChange={(v) => toggleTeam(team.id, v)} />
          ))}
        </Card>
      )}

      {form.type === 'match' && (
        <OpponentPicker
          opponents={opponents.data ?? []}
          value={form.opponent_id}
          onChange={(id) => set('opponent_id', id)}
          isHome={form.is_home}
          onHomeChange={(v) => set('is_home', v)}
        />
      )}

      <Card title={t('events.when')}>
        <div className="space-y-3">
          <div className="grid gap-3 sm:grid-cols-2">
            <Field
              label={t('events.start')}
              type="datetime-local"
              required
              value={form.starts_at}
              onChange={(e) => {
                const diff = (new Date(form.ends_at).getTime() - new Date(form.starts_at).getTime()) / 60000;
                setForm((f) => ({ ...f, starts_at: e.target.value, ends_at: addMinutes(e.target.value, diff > 0 ? diff : 120) }));
              }}
            />
            <Field label={t('events.end')} type="datetime-local" required value={form.ends_at} onChange={(e) => set('ends_at', e.target.value)} />
          </div>
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label={t('events.meetAt')} type="datetime-local" value={form.meet_at} onChange={(e) => set('meet_at', e.target.value)} />
            <Field
              label={t('events.deadlineHours')}
              type="number"
              min={0}
              max={336}
              inputMode="numeric"
              placeholder="–"
              value={form.deadline_hours}
              onChange={(e) => set('deadline_hours', e.target.value)}
              hint={deadlineFor(form.starts_at, form.deadline_hours)
                ? t('events.deadlineAt', { date: `${formatDay(new Date(deadlineFor(form.starts_at, form.deadline_hours)!).toISOString())} ${formatTime(new Date(deadlineFor(form.starts_at, form.deadline_hours)!).toISOString())}` })
                : t('events.deadlineHint')}
            />
          </div>
          {canRepeat && !eventId && <Toggle label={t('events.repeat')} checked={form.repeat} onChange={(v) => set('repeat', v)} />}
          {canRepeat && form.repeat && (
            <div className="space-y-3 rounded-lg bg-slate-50 p-3">
              <fieldset>
                <legend className="mb-1 text-sm font-medium text-slate-700">{t('events.weekdays')}</legend>
                <div className="flex flex-wrap gap-2">
                  {WEEKDAYS.map((day) => (
                    <label key={day} className={`flex min-h-10 min-w-10 items-center justify-center rounded-full border px-3 text-sm ${form.weekdays.includes(day) ? 'border-brand-600 bg-brand-50' : 'border-slate-300 bg-surface'}`}>
                      <input
                        type="checkbox"
                        className="sr-only"
                        checked={form.weekdays.includes(day)}
                        onChange={(e) => set('weekdays', e.target.checked ? [...form.weekdays, day] : form.weekdays.filter((d) => d !== day))}
                      />
                      {t(`events.weekdayShort.${day}`)}
                    </label>
                  ))}
                </div>
              </fieldset>
              <Select label={t('events.interval')} value={form.interval_weeks} onChange={(e) => set('interval_weeks', Number(e.target.value))}>
                <option value={1}>{t('events.everyWeek')}</option>
                <option value={2}>{t('events.everySecondWeek')}</option>
              </Select>
              <Field label={t('events.repeatUntil')} type="date" value={form.ends_on} onChange={(e) => set('ends_on', e.target.value)} hint={t('events.repeatUntilHint')} />
            </div>
          )}
        </div>
      </Card>

      {form.type === 'training' && (
        <TrainingOptions
          teamId={singleTeam}
          groupId={form.training_group_id}
          onGroupChange={(id) => set('training_group_id', id)}
          guestsAllowed={form.guests_allowed}
          onGuestsAllowedChange={(value) => set('guests_allowed', value)}
          guestsPositionsOnly={form.guests_positions_only}
          onGuestsPositionsOnlyChange={(value) => set('guests_positions_only', value)}
          minParticipants={form.min_participants}
          onMinParticipantsChange={(value) => set('min_participants', value)}
        />
      )}

      <Card title={t('events.where')}>
        <div className="space-y-3">
          {form.type === 'training' && (
            <HallSuggestions
              teamId={singleTeam}
              startsAt={form.starts_at}
              onPick={(hall, times) => setForm((f) => ({
                ...f,
                location_name: hall.name,
                address: hall.address ?? f.address,
                ...(times ? { starts_at: `${f.starts_at.slice(0, 10)}T${times.start}`, ends_at: `${f.starts_at.slice(0, 10)}T${times.end}` } : {}),
              }))}
            />
          )}
          <Field label={t('events.locationName')} maxLength={120} value={form.location_name} onChange={(e) => set('location_name', e.target.value)} />
          <Field label={t('events.address')} hint={t('events.addressHint')} maxLength={255} value={form.address} onChange={(e) => set('address', e.target.value)} />
        </div>
      </Card>

      <Card>
        <div className="space-y-3">
          <Field label={t('events.maxParticipants')} type="number" min={1} max={500} inputMode="numeric" value={form.max_participants} onChange={(e) => set('max_participants', e.target.value)} hint={t('invitations.optional')} />
          {form.type !== 'helper' && (form.type !== 'extra' || form.response_mode === 'rsvp') && (
            <details open={Object.values(form.position_limits).some(Boolean)}>
              <summary className="cursor-pointer text-sm font-medium text-slate-700">{t('events.positionLimits')}</summary>
              <p className="mt-1 text-xs text-slate-500">{t('events.positionLimitsHint')}</p>
              {ideal && (
                <button
                  type="button"
                  className="mt-2 rounded-md border border-brand-700 px-3 py-1.5 text-xs font-medium text-brand-700"
                  onClick={() => set('position_limits', Object.fromEntries(POSITIONS.map((p) => [p, ideal[p] === undefined ? '' : String(ideal[p])])))}
                >
                  {t('events.takeIdealSquad')}
                </button>
              )}
              <div className="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3">
                {POSITIONS.map((p) => (
                  <Field
                    key={p}
                    label={t(`positions.${p}`)}
                    type="number"
                    min={0}
                    max={50}
                    inputMode="numeric"
                    placeholder="–"
                    value={form.position_limits[p] ?? ''}
                    onChange={(e) => set('position_limits', { ...form.position_limits, [p]: e.target.value })}
                  />
                ))}
              </div>
            </details>
          )}
          <div>
            <label htmlFor="event-description" className="mb-1 block text-sm font-medium text-slate-700">{t('events.description')}</label>
            <textarea id="event-description" rows={3} maxLength={5000} className="w-full rounded-lg border border-slate-300 p-3 text-base" value={form.description} onChange={(e) => set('description', e.target.value)} />
          </div>
        </div>
      </Card>

      {!eventId && (
        <Card title={t('tasks.title')}>
          {(templates.data?.templates.length ?? 0) > 0 && (
            <>
              <Select label={t('tasks.fromTemplate')} value={taskTemplate} onChange={(e) => setTaskTemplate(e.target.value)}>
                <option value="">{t('tasks.noTemplate')}</option>
                {templates.data!.templates.map((tpl) => (
                  <option key={tpl.id} value={tpl.id}>{tpl.name} ({tpl.items.map((i) => i.title).join(', ')})</option>
                ))}
              </Select>
              <p className="mt-1 text-xs text-slate-500">{form.repeat ? t('tasks.templateForSeries') : t('tasks.templateHint')}</p>
            </>
          )}
          {!form.repeat && (
            <fieldset className={`min-w-0 space-y-2 ${(templates.data?.templates.length ?? 0) > 0 ? 'mt-4' : ''}`}>
              <legend className="mb-1 text-sm font-medium text-slate-700">{t('tasks.byHand')}</legend>
              <TaskRows rows={taskRows} onChange={setTaskRows} />
              <p className="text-xs text-slate-500">{t('tasks.byHandHint')}</p>
            </fieldset>
          )}
        </Card>
      )}

      {(save.isError || preview.isError) && <Notice tone="error">{errorText(save.error ?? preview.error, t('errors.generic'))}</Notice>}
      <SaveBar><Button type="submit" className="w-full" disabled={save.isPending || preview.isPending}>{t('common.save')}</Button></SaveBar>
      {dropped > 0 && (
        <ChoiceDialog
          title={t('events.dropped', { count: dropped })}
          onClose={() => setDropped(0)}
          busy={save.isPending}
          choices={[
            { label: t('events.droppedCancel'), variant: 'danger', onChoose: () => save.mutate('cancel', { onSettled: () => setDropped(0) }) },
            { label: t('events.droppedDelete'), variant: 'danger', onChoose: () => save.mutate('delete', { onSettled: () => setDropped(0) }) },
          ]}
        >
          <p>{t('events.droppedText')}</p>
        </ChoiceDialog>
      )}
    </form>
  );
}

/** Training group (own trainings for part of the team), minimum of players, guests. */
function TrainingOptions({
  teamId, groupId, onGroupChange, guestsAllowed, onGuestsAllowedChange, guestsPositionsOnly, onGuestsPositionsOnlyChange, minParticipants, onMinParticipantsChange,
}: {
  teamId: string | null;
  groupId: string;
  onGroupChange: (id: string) => void;
  guestsAllowed: boolean;
  onGuestsAllowedChange: (value: boolean) => void;
  guestsPositionsOnly: boolean;
  onGuestsPositionsOnlyChange: (value: boolean) => void;
  minParticipants: string;
  onMinParticipantsChange: (value: string) => void;
}) {
  const { t } = useTranslation();
  const tp = useTenantPath();
  const groups = useQuery({
    queryKey: ['training-groups', teamId],
    queryFn: async () => (await api<{ data: TrainingGroup[] }>(tp(`teams/${teamId}/training-groups`))).data,
    enabled: !!teamId,
  });

  return (
    <Card title={t('events.trainingOptions')}>
      <div className="space-y-3">
        {teamId && (groups.data?.length ?? 0) > 0 && (
          <Select label={t('events.trainingGroup')} value={groupId} onChange={(e) => onGroupChange(e.target.value)}>
            <option value="">{t('events.wholeTeam')}</option>
            {groups.data!.map((group) => <option key={group.id} value={group.id}>{group.name}</option>)}
          </Select>
        )}
        <Field
          label={t('events.minParticipants')}
          type="number"
          min={1}
          max={500}
          inputMode="numeric"
          placeholder="–"
          value={minParticipants}
          onChange={(e) => onMinParticipantsChange(e.target.value)}
          hint={t('events.minParticipantsHint')}
        />
        {!groupId && <Toggle label={t('events.guestsAllowed')} checked={guestsAllowed} onChange={onGuestsAllowedChange} />}
        {!groupId && <p className="-mt-2 text-xs text-slate-500">{t('events.guestsAllowedHint')}</p>}
        {!groupId && guestsAllowed && (
          <>
            <Toggle label={t('events.guestsPositionsOnly')} checked={guestsPositionsOnly} onChange={onGuestsPositionsOnlyChange} />
            <p className="-mt-2 text-xs text-slate-500">{t('events.guestsPositionsOnlyHint')}</p>
          </>
        )}
      </div>
    </Card>
  );
}

function OpponentPicker({ opponents, value, onChange, isHome, onHomeChange }: {
  opponents: Opponent[];
  value: string;
  onChange: (id: string) => void;
  isHome: FormState['is_home'];
  onHomeChange: (value: FormState['is_home']) => void;
}) {
  const { t } = useTranslation();
  const slug = useTenantSlug();
  const tp = useTenantPath();
  const queryClient = useQueryClient();
  const [name, setName] = useState('');

  const create = useMutation({
    mutationFn: () => post<{ data: Opponent }>(tp('opponents'), { name }),
    onSuccess: async ({ data }) => {
      setName('');
      await queryClient.invalidateQueries({ queryKey: ['opponents', slug] });
      onChange(data.id);
    },
  });
  const uploadLogo = useMutation({
    mutationFn: (file: File) => {
      const body = new FormData();
      body.append('image', file);
      return post(tp(`opponents/${value}/logo`), body);
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['opponents', slug] }),
  });
  const selected = opponents.find((o) => o.id === value);

  return (
    <Card title={t('events.opponent')}>
      <div className="space-y-3">
        <Select label={t('events.opponent')} value={value} onChange={(e) => onChange(e.target.value)}>
          <option value="">–</option>
          {opponents.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
        </Select>
        <div className="flex gap-2">
          <input
            className="min-h-11 min-w-0 flex-1 rounded-lg border border-slate-300 px-3 text-base"
            placeholder={t('events.newOpponent')}
            aria-label={t('events.newOpponent')}
            value={name}
            onChange={(e) => setName(e.target.value)}
          />
          <Button variant="secondary" disabled={!name.trim() || create.isPending} onClick={() => create.mutate()}>{t('common.add')}</Button>
        </div>
        {selected && (
          <div className="flex items-center gap-3">
            {selected.logo_url && <img src={selected.logo_url} alt="" className="vo-logo size-12 object-contain" />}
            <label className="inline-flex min-h-11 cursor-pointer items-center rounded-lg border border-slate-300 px-4 text-sm font-medium">
              {selected.logo_url ? t('events.changeLogo') : t('events.uploadLogo')}
              <input type="file" accept="image/png,image/jpeg,image/webp" className="sr-only" onChange={(e) => e.target.files?.[0] && uploadLogo.mutate(e.target.files[0])} />
            </label>
          </div>
        )}
        <Select label={t('events.homeAway')} value={isHome} onChange={(e) => onHomeChange(e.target.value as FormState['is_home'])}>
          <option value="">–</option>
          <option value="home">{t('events.home')}</option>
          <option value="away">{t('events.away')}</option>
        </Select>
      </div>
    </Card>
  );
}
