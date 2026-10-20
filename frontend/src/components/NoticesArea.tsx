import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { api, del, errorText, post, type Notice as NoticeType, type Team } from '../lib/api';
import { formatDateTime, toLocalInput } from '../lib/dates';
import { trainerTeamIds } from '../lib/permissions';
import { useTenantMe, useTenantPath, useTenantSlug } from '../lib/queries';
import { Button, Field, Notice, Select } from './ui';

/** Notice area on top of the start page; trainers post for their team, admins for everyone. */
export function NoticesArea() {
  const { t } = useTranslation();
  const slug = useTenantSlug();
  const tp = useTenantPath();
  const me = useTenantMe();
  const queryClient = useQueryClient();
  const [adding, setAdding] = useState(false);
  const notices = useQuery({ queryKey: ['notices', slug], queryFn: async () => (await api<{ data: NoticeType[] }>(tp('notices'))).data });
  const remove = useMutation({
    mutationFn: (id: string) => del(tp(`notices/${id}`)),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['notices', slug] }),
  });

  const canPost = !!me.data?.is_admin || trainerTeamIds(me.data).length > 0;

  return (
    <section aria-labelledby="notices-heading" className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="flex items-center justify-between gap-3">
        <h2 id="notices-heading" className="text-sm font-semibold uppercase tracking-wide text-slate-500">{t('home.infoArea')}</h2>
        {canPost && !adding && (
          <button type="button" className="text-sm text-brand-700 underline" onClick={() => setAdding(true)}>{t('notices.add')}</button>
        )}
      </div>
      {notices.data?.length === 0 && !adding && <p className="mt-2 text-sm text-slate-600">{t('home.noNotices')}</p>}
      <ul className="mt-2 space-y-3">
        {notices.data?.map((notice) => (
          <li key={notice.id} className="border-l-4 border-brand-600 pl-3">
            <p className="font-medium">{notice.title}</p>
            {notice.body && <p className="whitespace-pre-line text-sm text-slate-700">{notice.body}</p>}
            <p className="text-xs text-slate-500">
              {notice.team ? notice.team.name : t('notices.allTeams')} · {t('notices.until', { date: formatDateTime(notice.visible_until) })}
              {notice.can_delete && (
                <button type="button" className="ml-2 text-red-700 underline" onClick={() => remove.mutate(notice.id)}>{t('notices.remove')}</button>
              )}
            </p>
          </li>
        ))}
      </ul>
      {adding && <NoticeForm onDone={() => setAdding(false)} />}
    </section>
  );
}

function NoticeForm({ onDone }: { onDone: () => void }) {
  const { t } = useTranslation();
  const slug = useTenantSlug();
  const tp = useTenantPath();
  const me = useTenantMe();
  const queryClient = useQueryClient();
  const teams = useQuery({ queryKey: ['teams', slug], queryFn: async () => (await api<{ data: Team[] }>(tp('teams'))).data });
  const trainerTeams = trainerTeamIds(me.data);
  const options = (teams.data ?? []).filter((team) => me.data?.is_admin || trainerTeams.includes(team.id));

  const now = new Date();
  // Every info expires: no preset, so the expiry date is chosen on purpose.
  const [form, setForm] = useState({
    target: me.data?.is_admin ? 'tenant' : (trainerTeams[0] ?? ''),
    title: '',
    body: '',
    visible_from: toLocalInput(now.toISOString()),
    visible_until: '',
  });
  const expiresIn = (days: number) => {
    const from = new Date(form.visible_from || now.toISOString());
    const until = new Date(Number.isNaN(from.getTime()) ? now.getTime() : from.getTime());
    until.setDate(until.getDate() + days);
    until.setHours(23, 59, 0, 0);
    set('visible_until')(toLocalInput(until.toISOString()));
  };
  const set = (key: keyof typeof form) => (value: string) => setForm((f) => ({ ...f, [key]: value }));

  const create = useMutation({
    mutationFn: () => post(tp('notices'), {
      scope: form.target === 'tenant' ? 'tenant' : 'team',
      team_id: form.target === 'tenant' ? null : form.target,
      title: form.title,
      body: form.body || null,
      visible_from: form.visible_from,
      visible_until: form.visible_until,
    }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['notices', slug] });
      onDone();
    },
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    create.mutate();
  };

  return (
    <form onSubmit={submit} className="mt-3 space-y-3 border-t border-slate-100 pt-3">
      <Select label={t('notices.for')} value={form.target} onChange={(e) => set('target')(e.target.value)}>
        {me.data?.is_admin && <option value="tenant">{t('notices.allTeams')}</option>}
        {options.map((team) => <option key={team.id} value={team.id}>{team.name}</option>)}
      </Select>
      <Field label={t('notices.title')} required maxLength={120} value={form.title} onChange={(e) => set('title')(e.target.value)} />
      <div>
        <label className="mb-1 block text-sm font-medium text-slate-700" htmlFor="notice-body">{t('notices.body')}</label>
        <textarea id="notice-body" rows={3} maxLength={2000} className="w-full rounded-lg border border-slate-300 p-3 text-base" value={form.body} onChange={(e) => set('body')(e.target.value)} />
      </div>
      <Field label={t('notices.from')} type="datetime-local" required value={form.visible_from} onChange={(e) => set('visible_from')(e.target.value)} />
      <div>
        <Field
          label={t('notices.to')}
          hint={t('notices.toHint')}
          type="datetime-local"
          required
          min={form.visible_from || undefined}
          value={form.visible_until}
          onChange={(e) => set('visible_until')(e.target.value)}
        />
        <div className="mt-2 flex flex-wrap gap-2">
          {([[1, 'day'], [7, 'week'], [30, 'month'], [91, 'quarter']] as const).map(([days, key]) => (
            <button key={key} type="button" className="min-h-9 rounded-full border border-slate-300 px-3 text-sm" onClick={() => expiresIn(days)}>
              {t(`notices.expiresIn.${key}`)}
            </button>
          ))}
        </div>
      </div>
      {create.isError && <Notice tone="error">{errorText(create.error, t('errors.generic'))}</Notice>}
      <div className="flex gap-2">
        <Button type="submit" disabled={create.isPending || !form.visible_until}>{t('notices.publish')}</Button>
        <Button variant="secondary" onClick={onDone}>{t('common.cancel')}</Button>
      </div>
    </form>
  );
}
