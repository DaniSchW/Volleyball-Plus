import { useEffect, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { useNavigate, useParams, useSearchParams } from 'react-router';
import { api, errorText, patch, post, type EventDetail, type EventType, type Opponent, type Team, type TrainingGroup } from '../lib/api';
import { addMinutes, toLocalInput } from '../lib/dates';
import { useTenantMe, useTenantPath, useTenantSlug } from '../lib/queries';
import { Button, Card, Field, Loading, Notice, PageTitle, Select, Toggle } from '../components/ui';

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
  rsvp_deadline: string;
  location_name: string;
  address: string;
  max_participants: string;
  opponent_id: string;
  is_home: '' | 'home' | 'away';
  training_group_id: string;
  guests_from: string;
  repeat: boolean;
  weekdays: number[];
  interval_weeks: number;
  ends_on: string;
}

function initial(teamId?: string): FormState {
  const start = new Date();
  start.setDate(start.getDate() + 1);
  start.setHours(19, 30, 0, 0);
  const startLocal = toLocalInput(start.toISOString());
  return {
    type: 'training', title: '', description: '', allTeams: false, team_ids: teamId ? [teamId] : [], response_mode: 'rsvp',
    starts_at: startLocal, ends_at: addMinutes(startLocal, 120), meet_at: '', rsvp_deadline: '',
    location_name: '', address: '', max_participants: '', opponent_id: '', is_home: '', training_group_id: '', guests_from: '',
    repeat: false, weekdays: [((start.getDay() + 6) % 7) + 1], interval_weeks: 1, ends_on: '',
  };
}

function fromEvent(e: EventDetail): FormState {
  return {
    ...initial(),
    type: e.type, title: e.title, description: e.description ?? '', allTeams: e.scope === 'tenant', team_ids: e.team_ids,
    response_mode: e.response_mode, starts_at: toLocalInput(e.starts_at), ends_at: toLocalInput(e.ends_at),
    meet_at: toLocalInput(e.meet_at), rsvp_deadline: toLocalInput(e.rsvp_deadline), location_name: e.location_name ?? '',
    address: e.address ?? '', max_participants: e.max_participants?.toString() ?? '', opponent_id: e.opponent?.id ?? '',
    is_home: e.is_home === null ? '' : e.is_home ? 'home' : 'away',
    training_group_id: e.training_group?.id ?? '', guests_from: toLocalInput(e.cross_nomination_opens_at),
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

  const save = useMutation({
    mutationFn: () => {
      const body: Record<string, unknown> = {
        type: form.type,
        title: form.title || null,
        description: form.description || null,
        starts_at: form.starts_at,
        ends_at: form.ends_at,
        meet_at: form.meet_at || null,
        rsvp_deadline: form.rsvp_deadline || null,
        location_name: form.location_name || null,
        address: form.address || null,
        max_participants: form.max_participants ? Number(form.max_participants) : null,
        response_mode: form.type === 'extra' ? form.response_mode : form.type === 'helper' ? 'info' : 'rsvp',
      };
      if (form.type === 'training') {
        body.training_group_id = form.training_group_id || null;
        body.cross_nomination_opens_at = form.guests_from || null;
      }
      if (form.type === 'match') {
        body.opponent_id = form.opponent_id || null;
        body.is_home = form.is_home === '' ? null : form.is_home === 'home';
      }
      if (!eventId) {
        body.scope = form.allTeams ? 'tenant' : 'team';
        body.team_ids = form.allTeams ? [] : form.team_ids;
      }
      if (form.repeat) body.repeat = { weekdays: form.weekdays, interval_weeks: form.interval_weeks, ends_on: form.ends_on || null };

      return eventId
        ? patch<{ data: EventDetail }>(`${tp(`events/${eventId}`)}${editSeries ? '?series=1' : ''}`, body)
        : post<{ data: EventDetail }>(tp('events'), body);
    },
    onSuccess: async ({ data }) => {
      await queryClient.invalidateQueries({ queryKey: ['events'] });
      queryClient.setQueryData(['event', data.id], data);
      navigate(`/${slug}/events/${data.id}`, { replace: true });
    },
  });

  if (teams.isPending || (eventId && existing.isPending)) return <Loading />;

  const submit = (event: FormEvent) => {
    event.preventDefault();
    save.mutate();
  };

  const singleTeam = !form.allTeams && form.team_ids.length === 1 ? form.team_ids[0] : null;

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
            <Field label={t('events.deadline')} type="datetime-local" value={form.rsvp_deadline} onChange={(e) => set('rsvp_deadline', e.target.value)} hint={t('events.deadlineHint')} />
          </div>
          {canRepeat && !eventId && <Toggle label={t('events.repeat')} checked={form.repeat} onChange={(v) => set('repeat', v)} />}
          {canRepeat && form.repeat && (
            <div className="space-y-3 rounded-lg bg-slate-50 p-3">
              <fieldset>
                <legend className="mb-1 text-sm font-medium text-slate-700">{t('events.weekdays')}</legend>
                <div className="flex flex-wrap gap-2">
                  {WEEKDAYS.map((day) => (
                    <label key={day} className={`flex min-h-10 min-w-10 items-center justify-center rounded-full border px-3 text-sm ${form.weekdays.includes(day) ? 'border-brand-600 bg-brand-50' : 'border-slate-300 bg-white'}`}>
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
          guestsFrom={form.guests_from}
          onGuestsFromChange={(value) => set('guests_from', value)}
        />
      )}

      <Card title={t('events.where')}>
        <div className="space-y-3">
          <Field label={t('events.locationName')} maxLength={120} value={form.location_name} onChange={(e) => set('location_name', e.target.value)} />
          <Field label={t('events.address')} hint={t('events.addressHint')} maxLength={255} value={form.address} onChange={(e) => set('address', e.target.value)} />
        </div>
      </Card>

      <Card>
        <div className="space-y-3">
          <Field label={t('events.maxParticipants')} type="number" min={1} max={500} inputMode="numeric" value={form.max_participants} onChange={(e) => set('max_participants', e.target.value)} hint={t('invitations.optional')} />
          <div>
            <label htmlFor="event-description" className="mb-1 block text-sm font-medium text-slate-700">{t('events.description')}</label>
            <textarea id="event-description" rows={3} maxLength={5000} className="w-full rounded-lg border border-slate-300 p-3 text-base" value={form.description} onChange={(e) => set('description', e.target.value)} />
          </div>
        </div>
      </Card>

      {save.isError && <Notice tone="error">{errorText(save.error, t('errors.generic'))}</Notice>}
      <Button type="submit" className="w-full" disabled={save.isPending}>{t('common.save')}</Button>
    </form>
  );
}

/** Training group (own trainings for part of the team) and when cross-nominated guests may accept. */
function TrainingOptions({ teamId, groupId, onGroupChange, guestsFrom, onGuestsFromChange }: {
  teamId: string | null;
  groupId: string;
  onGroupChange: (id: string) => void;
  guestsFrom: string;
  onGuestsFromChange: (value: string) => void;
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
        {!groupId && (
          <Field
            label={t('events.guestsFrom')}
            type="datetime-local"
            value={guestsFrom}
            onChange={(e) => onGuestsFromChange(e.target.value)}
            hint={t('events.guestsFromHint')}
          />
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
            {selected.logo_url && <img src={selected.logo_url} alt="" className="size-12 object-contain" />}
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
