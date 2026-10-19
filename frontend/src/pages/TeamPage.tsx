import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link, useParams, useSearchParams } from 'react-router';
import {
  api, del, errorText, patch, post,
  type Invitation, type RosterRow, type Team, type TeamRole, type TeamSettings,
} from '../lib/api';
import { useTenantMe, useTenantPath, useTenantSlug } from '../lib/queries';
import { Avatar, Button, Card, Field, Loading, Notice, PageTitle, Select, Toggle } from '../components/ui';
import { EventFeed } from '../components/events/EventFeed';
import { LogoUpload } from '../components/LogoUpload';
import { AddFromDepartment } from '../components/team/AddFromDepartment';
import { RefereeBadge } from '../components/team/RefereeBadge';
import { TeamBaseData } from '../components/team/TeamBaseData';
import { TeamGuests } from '../components/team/TeamGuests';
import { TeamHallTimes } from '../components/team/TeamHallTimes';
import { TeamStatistics } from '../components/team/TeamStatistics';
import { TrainingGroups } from '../components/team/TrainingGroups';

type Tab = 'roster' | 'events' | 'statistics' | 'groups' | 'guests' | 'invitations' | 'settings';

export function TeamPage() {
  const { t } = useTranslation();
  const { teamId = '' } = useParams();
  const tp = useTenantPath();
  const slug = useTenantSlug();
  const me = useTenantMe();
  const [params] = useSearchParams();
  const [tab, setTab] = useState<Tab>((params.get('tab') as Tab | null) ?? 'roster');
  const queryClient = useQueryClient();

  const team = useQuery({ queryKey: ['team', teamId], queryFn: async () => (await api<{ data: Team }>(tp(`teams/${teamId}`))).data });

  if (team.isPending) return <Loading />;
  if (team.isError) return <Notice tone="error">{errorText(team.error, t('errors.generic'))}</Notice>;

  const managed = team.data.can_invite;
  const tabs: Tab[] = [
    'roster', 'events', 'statistics', 'groups',
    ...(managed && !team.data.is_youth ? (['guests'] as Tab[]) : []),
    ...(managed ? (['invitations'] as Tab[]) : []),
    ...(team.data.can_manage ? (['settings'] as Tab[]) : []),
  ];

  return (
    <div className="space-y-4">
      <PageTitle>{team.data.name}</PageTitle>
      {tabs.length > 1 && (
        <div role="tablist" className="-mx-4 flex gap-1 overflow-x-auto bg-slate-100 p-1 px-4 sm:mx-0 sm:rounded-lg sm:px-1">
          {tabs.map((value) => (
            <button
              key={value}
              role="tab"
              type="button"
              aria-selected={tab === value}
              onClick={() => setTab(value)}
              className={`min-h-10 shrink-0 grow whitespace-nowrap rounded-md px-3 text-sm ${tab === value ? 'bg-brand-700 font-medium text-white shadow-sm' : 'text-slate-600'}`}
            >
              {t(`team.tabs.${value}`)}
            </button>
          ))}
        </div>
      )}
      {tab === 'roster' && (
        <>
          <TeamHallTimes teamId={team.data.id} />
          <Roster team={team.data} />
        </>
      )}
      {tab === 'statistics' && <TeamStatistics team={team.data} />}
      {tab === 'groups' && <TrainingGroups team={team.data} />}
      {tab === 'guests' && <TeamGuests team={team.data} />}
      {tab === 'invitations' && <Invitations team={team.data} />}
      {tab === 'settings' && team.data.settings && (
        <>
          {me.data?.is_admin && <TeamBaseData team={team.data} />}
          <LogoUpload
            title={t('team.logo')}
            path={tp(`teams/${team.data.id}/logo`)}
            currentUrl={team.data.logo_url ?? null}
            onUploaded={() => void queryClient.invalidateQueries({ queryKey: ['team', teamId] })}
          />
          <Settings team={team.data} settings={team.data.settings} />
        </>
      )}
      {tab === 'events' && (
        <>
          {team.data.can_invite && (
            <Link to={`/${slug}/teams/${team.data.id}/import`} className="inline-flex min-h-11 items-center rounded-lg border border-slate-300 bg-white px-4 text-sm font-medium">
              📥 {t('import.open')}
            </Link>
          )}
          <EventFeed teamId={team.data.id} />
        </>
      )}
    </div>
  );
}

const ROLE_ORDER: TeamRole[] = ['trainer', 'organizer', 'player'];

function Roster({ team }: { team: Team }) {
  const { t } = useTranslation();
  const slug = useTenantSlug();
  const tp = useTenantPath();
  const queryClient = useQueryClient();
  const roster = useQuery({
    queryKey: ['roster', team.id],
    queryFn: async () => (await api<{ data: RosterRow[] }>(tp(`teams/${team.id}/members`))).data,
  });
  const remove = useMutation({
    mutationFn: (membershipId: string) => del(tp(`teams/${team.id}/members/${membershipId}`)),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['roster', team.id] }),
  });

  if (roster.isPending) return <Loading />;
  if (roster.isError) return <Notice tone="error">{t('errors.generic')}</Notice>;

  return (
    <div className="space-y-4">
      {ROLE_ORDER.map((role) => {
        const rows = roster.data.filter((row) => row.roles.some((r) => r.role === role));
        if (rows.length === 0) return null;
        return (
          <Card key={role} title={`${t(`roles.plural.${role}`)} (${rows.length})`}>
            <ul className="divide-y divide-slate-100">
              {rows.map(({ person, roles, plays_higher_in }) => {
                const membership = roles.find((r) => r.role === role)!;
                const name = `${person.first_name} ${person.last_name}`;
                return (
                  <li key={person.id} className="flex items-center gap-3 py-2">
                    <Avatar url={person.avatar_url} name={name} />
                    <Link to={`/${slug}/persons/${person.id}`} className="min-w-0 flex-1">
                      <span className="block truncate font-medium">
                        {person.jersey_number != null && <span className="mr-1 text-slate-500">#{person.jersey_number}</span>}
                        {name}
                      </span>
                      <span className="block truncate text-xs text-slate-500">
                        {[person.primary_position && t(`positions.${person.primary_position}`), person.is_minor && t('profile.minor')].filter(Boolean).join(' · ')}
                      </span>
                      {plays_higher_in && role === 'player' && (
                        <span className="block text-xs text-amber-800">{t('team.playsHigher', { team: plays_higher_in })}</span>
                      )}
                      {person.referee_license && <RefereeBadge license={person.referee_license} />}
                    </Link>
                    {team.can_manage && (
                      <button
                        type="button"
                        className="rounded p-2 text-sm text-red-700 hover:bg-red-50"
                        aria-label={t('team.removeFromTeam', { name })}
                        onClick={() => window.confirm(t('team.removeConfirm', { name })) && remove.mutate(membership.id)}
                      >
                        ✕
                      </button>
                    )}
                  </li>
                );
              })}
            </ul>
          </Card>
        );
      })}
      {roster.data.length === 0 && <Notice>{t('team.empty')}</Notice>}
      {team.can_manage && <AddFromDepartment team={team} memberIds={new Set(roster.data.map((row) => row.person.id))} />}
      {team.can_manage && <AddPerson team={team} />}
    </div>
  );
}

/** Create a profile without login (e.g. a child) – parents are invited from its profile page. */
function AddPerson({ team }: { team: Team }) {
  const { t } = useTranslation();
  const tp = useTenantPath();
  const queryClient = useQueryClient();
  const [form, setForm] = useState({ first_name: '', last_name: '', gender: 'male', birthday: '', role: 'player' });
  const set = (key: keyof typeof form) => (value: string) => setForm((f) => ({ ...f, [key]: value }));

  const create = useMutation({
    mutationFn: () => post(tp('persons'), { ...form, birthday: form.birthday || null, team_id: team.id }),
    onSuccess: () => {
      setForm({ first_name: '', last_name: '', gender: 'male', birthday: '', role: 'player' });
      void queryClient.invalidateQueries({ queryKey: ['roster', team.id] });
    },
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    create.mutate();
  };

  return (
    <Card title={t('team.addPerson')}>
      <p className="mb-3 text-sm text-slate-600">{t('team.addPersonHint')}</p>
      <form onSubmit={submit} className="space-y-3">
        <div className="grid grid-cols-2 gap-3">
          <Field label={t('profile.fields.first_name')} required value={form.first_name} onChange={(e) => set('first_name')(e.target.value)} />
          <Field label={t('profile.fields.last_name')} required value={form.last_name} onChange={(e) => set('last_name')(e.target.value)} />
        </div>
        <div className="grid grid-cols-2 gap-3">
          <Select label={t('profile.fields.gender')} value={form.gender} onChange={(e) => set('gender')(e.target.value)}>
            <option value="male">{t('genders.male')}</option>
            <option value="female">{t('genders.female')}</option>
          </Select>
          <Select label={t('team.role')} value={form.role} onChange={(e) => set('role')(e.target.value)}>
            {ROLE_ORDER.map((role) => <option key={role} value={role}>{t(`roles.${role}`)}</option>)}
          </Select>
        </div>
        <Field label={t('profile.fields.birthday')} type="date" value={form.birthday} onChange={(e) => set('birthday')(e.target.value)} />
        {create.isError && <Notice tone="error">{errorText(create.error, t('errors.generic'))}</Notice>}
        <Button type="submit" disabled={create.isPending}>{t('common.add')}</Button>
      </form>
    </Card>
  );
}

function Invitations({ team }: { team: Team }) {
  const { t } = useTranslation();
  const tp = useTenantPath();
  const queryClient = useQueryClient();
  const key = ['invitations', team.id];
  const links = useQuery({ queryKey: key, queryFn: async () => (await api<{ data: Invitation[] }>(tp(`teams/${team.id}/invitations`))).data });

  const [role, setRole] = useState<TeamRole>('player');
  const [days, setDays] = useState('');
  const [maxUses, setMaxUses] = useState('');

  const create = useMutation({
    mutationFn: () => post(tp(`teams/${team.id}/invitations`), {
      role,
      expires_in_days: days ? Number(days) : null,
      max_uses: maxUses ? Number(maxUses) : null,
    }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: key }),
  });
  const revoke = useMutation({
    mutationFn: (id: string) => del(tp(`invitations/${id}`)),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: key }),
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    create.mutate();
  };

  const roles: TeamRole[] = team.can_manage ? ROLE_ORDER : ['player'];

  return (
    <div className="space-y-4">
      <Card title={t('invitations.active')}>
        {links.isPending && <Loading />}
        {links.data?.length === 0 && <p className="text-sm text-slate-500">{t('invitations.none')}</p>}
        <ul className="space-y-3">
          {links.data?.map((link) => (
            <li key={link.id} className="rounded-lg border border-slate-200 p-3">
              <p className="text-sm font-medium">{t(`roles.${link.role}`)}</p>
              <p className="text-xs text-slate-500">
                {t('invitations.uses', { count: link.use_count })}
                {link.max_uses ? ` / ${link.max_uses}` : ''}
                {link.expires_at ? ` · ${t('invitations.until', { date: new Date(link.expires_at).toLocaleDateString() })}` : ''}
              </p>
              <ShareLink url={link.url} title={t('invitations.shareTitle', { team: team.name })} />
              <button type="button" className="mt-2 text-sm text-red-700 underline" onClick={() => revoke.mutate(link.id)}>
                {t('invitations.revoke')}
              </button>
            </li>
          ))}
        </ul>
      </Card>

      <Card title={t('invitations.create')}>
        <form onSubmit={submit} className="space-y-3">
          <Select label={t('team.role')} value={role} onChange={(e) => setRole(e.target.value as TeamRole)}>
            {roles.map((value) => <option key={value} value={value}>{t(`roles.${value}`)}</option>)}
          </Select>
          <div className="grid grid-cols-2 gap-3">
            <Field label={t('invitations.days')} type="number" min={1} max={365} inputMode="numeric" value={days} onChange={(e) => setDays(e.target.value)} hint={t('invitations.optional')} />
            <Field label={t('invitations.maxUses')} type="number" min={1} max={500} inputMode="numeric" value={maxUses} onChange={(e) => setMaxUses(e.target.value)} hint={t('invitations.optional')} />
          </div>
          {create.isError && <Notice tone="error">{errorText(create.error, t('errors.generic'))}</Notice>}
          <Button type="submit" disabled={create.isPending}>{t('invitations.createButton')}</Button>
        </form>
      </Card>
    </div>
  );
}

/** Copy or share (WhatsApp, Signal, … via the phone's share sheet) an invitation link. */
export function ShareLink({ url, title }: { url: string; title: string }) {
  const { t } = useTranslation();
  const [copied, setCopied] = useState(false);

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(url);
      setCopied(true);
    } catch {
      window.prompt(t('invitations.copyManually'), url);
    }
  };

  return (
    <div className="mt-2 space-y-2">
      <input readOnly value={url} className="w-full rounded border border-slate-200 bg-slate-50 px-2 py-1 text-xs" onFocus={(e) => e.target.select()} />
      <div className="flex gap-2">
        <Button variant="secondary" onClick={copy}>{copied ? t('invitations.copied') : t('invitations.copy')}</Button>
        {'share' in navigator && (
          <Button variant="secondary" onClick={() => void navigator.share({ title, url }).catch(() => undefined)}>
            {t('invitations.share')}
          </Button>
        )}
      </div>
    </div>
  );
}

function Settings({ team, settings }: { team: Team; settings: TeamSettings }) {
  const { t } = useTranslation();
  const tp = useTenantPath();
  const queryClient = useQueryClient();
  const [form, setForm] = useState<TeamSettings>(settings);
  const set = <K extends keyof TeamSettings>(key: K, value: TeamSettings[K]) => setForm((f) => ({ ...f, [key]: value }));
  const num = (value: string) => (value === '' ? null : Number(value));

  const save = useMutation({
    mutationFn: () => patch<{ data: Team }>(tp(`teams/${team.id}`), { ...form }),
    onSuccess: ({ data }) => queryClient.setQueryData(['team', team.id], data),
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    save.mutate();
  };

  return (
    <form onSubmit={submit} className="space-y-4">
      <Card title={t('settings.rsvp')}>
        <div className="space-y-3">
          <Select label={t('settings.rsvpVisibility')} value={form.rsvp_visibility} onChange={(e) => set('rsvp_visibility', e.target.value as TeamSettings['rsvp_visibility'])}>
            <option value="names">{t('settings.rsvpNames')}</option>
            <option value="counts">{t('settings.rsvpCounts')}</option>
          </Select>
          <Toggle label={t('settings.declineReasonRequired')} checked={form.decline_reason_required} onChange={(v) => set('decline_reason_required', v)} />
          <Toggle label={t('settings.maybeReasonRequired')} checked={form.maybe_reason_required} onChange={(v) => set('maybe_reason_required', v)} />
          <Toggle label={t('settings.reasonsVisible')} checked={form.reasons_visible_to_teammates} onChange={(v) => set('reasons_visible_to_teammates', v)} />
        </div>
      </Card>

      <Card title={t('settings.training')}>
        <div className="grid grid-cols-2 gap-3">
          <Field label={t('settings.minParticipants')} type="number" min={1} max={99} inputMode="numeric" value={form.min_participants ?? ''} onChange={(e) => set('min_participants', num(e.target.value))} />
          <Field label={t('settings.maxParticipants')} type="number" min={1} max={99} inputMode="numeric" value={form.default_max_participants ?? ''} onChange={(e) => set('default_max_participants', num(e.target.value))} />
        </div>
      </Card>

      {save.isError && <Notice tone="error">{errorText(save.error, t('errors.generic'))}</Notice>}
      {save.isSuccess && <Notice tone="success">{t('common.saved')}</Notice>}
      <Button type="submit" disabled={save.isPending}>{t('common.save')}</Button>
    </form>
  );
}
