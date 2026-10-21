import { NotifyChoice, type EmailMode } from '../components/polls/NotifyChoice';
import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { api, errorText, post, type Poll, type Team } from '../lib/api';
import { useTenantMe, useTenantPath, useTenantSlug } from '../lib/queries';
import { Button, Card, Field, Loading, Notice, PageTitle, SaveBar, Toggle } from '../components/ui';
import { PollCard } from '../components/polls/PollCard';

/** Polls of my teams; trainers/organizers create team polls, admins also for all teams. */
export function PollsPage() {
  const { t } = useTranslation();
  const slug = useTenantSlug();
  const tp = useTenantPath();
  const polls = useQuery({ queryKey: ['polls', slug], queryFn: async () => (await api<{ data: Poll[] }>(tp('polls'))).data });
  const teams = useQuery({ queryKey: ['teams', slug], queryFn: async () => (await api<{ data: Team[] }>(tp('teams'))).data });
  const me = useTenantMe();
  const [creating, setCreating] = useState(false);

  if (polls.isPending) return <Loading />;
  if (polls.isError) return <Notice tone="error">{t('errors.generic')}</Notice>;

  const manageable = (teams.data ?? []).filter((team) => team.can_invite);
  const canCreate = manageable.length > 0 || !!me.data?.is_admin;
  const open = polls.data.filter((p) => !p.is_closed);
  const closed = polls.data.filter((p) => p.is_closed);

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between gap-3">
        <PageTitle>{t('menu.polls')}</PageTitle>
        {canCreate && !creating && <Button onClick={() => setCreating(true)}>{t('polls.create')}</Button>}
      </div>
      {creating && <CreatePoll teams={manageable} isAdmin={!!me.data?.is_admin} onDone={() => setCreating(false)} />}
      {open.length === 0 && !creating && <Notice>{t('polls.none')}</Notice>}
      {open.map((poll) => <PollCard key={poll.id} poll={poll} />)}
      {closed.length > 0 && <h2 className="pt-2 text-sm font-semibold text-slate-600">{t('polls.closedPolls')}</h2>}
      {closed.map((poll) => <PollCard key={poll.id} poll={poll} />)}
    </div>
  );
}

function CreatePoll({ teams, isAdmin, onDone }: { teams: Team[]; isAdmin: boolean; onDone: () => void }) {
  const { t } = useTranslation();
  const tp = useTenantPath();
  const queryClient = useQueryClient();
  const [form, setForm] = useState({
    title: '', description: '', allTeams: false, team_ids: teams.length === 1 ? [teams[0].id] : ([] as string[]),
    options: ['', ''], multiple_choice: false, anonymous: false, allow_free_text: false, closes_at: '',
    notify_push: true, notify_email: 'without_push' as EmailMode,
  });
  const set = <K extends keyof typeof form>(key: K, value: (typeof form)[K]) => setForm((f) => ({ ...f, [key]: value }));

  const create = useMutation({
    mutationFn: () => post(tp('polls'), {
      title: form.title, description: form.description || null,
      scope: form.allTeams ? 'tenant' : 'team', team_ids: form.allTeams ? [] : form.team_ids,
      options: form.options.map((o) => o.trim()).filter(Boolean),
      multiple_choice: form.multiple_choice, anonymous: form.anonymous, allow_free_text: form.allow_free_text,
      closes_at: form.closes_at || null,
      notify_push: form.notify_push, notify_email: form.notify_email,
    }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['polls'] });
      onDone();
    },
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    create.mutate();
  };

  return (
    <form onSubmit={submit}>
      <Card title={t('polls.create')}>
        <div className="space-y-3">
          <Field label={t('polls.question')} required maxLength={160} value={form.title} onChange={(e) => set('title', e.target.value)} />
          <div>
            <label htmlFor="poll-description" className="mb-1 block text-sm font-medium text-slate-700">{t('polls.description')}</label>
            <textarea id="poll-description" rows={2} maxLength={2000} className="w-full rounded-lg border border-slate-300 p-3 text-base" value={form.description} onChange={(e) => set('description', e.target.value)} />
          </div>
          <fieldset className="space-y-2">
            <legend className="text-sm font-medium text-slate-700">{t('polls.options')}</legend>
            {form.options.map((option, i) => (
              <input
                key={i}
                className="min-h-11 w-full rounded-lg border border-slate-300 px-3 text-base"
                aria-label={t('polls.optionN', { n: i + 1 })}
                placeholder={t('polls.optionN', { n: i + 1 })}
                maxLength={160}
                value={option}
                onChange={(e) => set('options', form.options.map((o, j) => (j === i ? e.target.value : o)))}
              />
            ))}
            {form.options.length < 20 && (
              <button type="button" className="text-sm text-brand-700 underline" onClick={() => set('options', [...form.options, ''])}>{t('polls.addOption')}</button>
            )}
          </fieldset>
          <div>
            {isAdmin && <Toggle label={t('polls.allTeams')} checked={form.allTeams} onChange={(v) => set('allTeams', v)} />}
            {!form.allTeams && teams.map((team) => (
              <Toggle key={team.id} label={team.name} checked={form.team_ids.includes(team.id)}
                onChange={(v) => set('team_ids', v ? [...form.team_ids, team.id] : form.team_ids.filter((x) => x !== team.id))} />
            ))}
          </div>
          <Toggle label={t('polls.multiple')} checked={form.multiple_choice} onChange={(v) => set('multiple_choice', v)} />
          <Toggle label={t('polls.anonymousLabel')} checked={form.anonymous} onChange={(v) => set('anonymous', v)} />
          <Toggle label={t('polls.allowFreeText')} checked={form.allow_free_text} onChange={(v) => set('allow_free_text', v)} />
          <Field label={t('polls.closesAt')} type="datetime-local" value={form.closes_at} onChange={(e) => set('closes_at', e.target.value)} hint={t('invitations.optional')} />
          <NotifyChoice push={form.notify_push} email={form.notify_email} onPush={(v) => set('notify_push', v)} onEmail={(v) => set('notify_email', v)} />
          {create.isError && <Notice tone="error">{errorText(create.error, t('errors.generic'))}</Notice>}
          <SaveBar>
            <Button type="submit" disabled={create.isPending}>{t('polls.publish')}</Button>
            <Button variant="secondary" onClick={onDone}>{t('common.cancel')}</Button>
          </SaveBar>
        </div>
      </Card>
    </form>
  );
}
