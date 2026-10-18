import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { api, del, errorText, patch, post, type League } from '../lib/api';
import { useTenantMe, useTenantPath, useTenantSlug } from '../lib/queries';
import { Button, Card, Field, Loading, Notice, PageTitle, Select } from '../components/ui';

/**
 * League tree (admins): levels from the top league down. The level decides
 * the order of the teams for the hierarchy and cross-nomination.
 */
export function LeaguesPage() {
  const { t } = useTranslation();
  const slug = useTenantSlug();
  const tp = useTenantPath();
  const me = useTenantMe();
  const queryClient = useQueryClient();
  const key = ['leagues', slug];
  const leagues = useQuery({ queryKey: key, queryFn: async () => (await api<{ data: League[] }>(tp('leagues'))).data });
  const [editing, setEditing] = useState<string | null>(null);

  const done = ({ data }: { data: League[] }) => {
    queryClient.setQueryData(key, data);
    void queryClient.invalidateQueries({ queryKey: ['teams', slug] });
    setEditing(null);
  };
  const update = useMutation({
    mutationFn: ({ id, ...body }: { id: string; name: string; level: number }) => patch<{ data: League[] }>(tp(`leagues/${id}`), body),
    onSuccess: done,
  });
  const remove = useMutation({ mutationFn: (id: string) => del<{ data: League[] }>(tp(`leagues/${id}`)), onSuccess: done });

  if (leagues.isPending || me.isPending) return <Loading />;
  if (!me.data?.is_admin) return <Notice tone="error">{t('tenant.noAccess')}</Notice>;

  const hasChildren = (id: string) => leagues.data!.some((l) => l.parent_id === id);

  return (
    <div className="space-y-4">
      <PageTitle>{t('leagues.title')}</PageTitle>
      <p className="text-sm text-slate-600">{t('leagues.hint')}</p>

      <Card>
        <ul className="divide-y divide-slate-100">
          {leagues.data!.map((league) => (
            <li key={league.id} className="py-2" style={{ paddingLeft: `${league.depth * 1.25}rem` }}>
              {editing === league.id ? (
                <EditLeague league={league} onSave={(body) => update.mutate({ id: league.id, ...body })} onCancel={() => setEditing(null)} />
              ) : (
                <div className="flex items-center gap-2">
                  <span className="w-8 shrink-0 text-right text-xs text-slate-500" title={t('leagues.level')}>{league.level}</span>
                  <span className="min-w-0 flex-1 truncate text-sm">
                    {league.name}
                    {league.team_count > 0 && <span className="text-xs text-slate-500"> · {t('leagues.teams', { count: league.team_count })}</span>}
                  </span>
                  <button type="button" className="rounded px-2 py-1 text-sm text-brand-700" onClick={() => setEditing(league.id)}>{t('leagues.edit')}</button>
                  {league.team_count === 0 && !hasChildren(league.id) && (
                    <button
                      type="button"
                      className="rounded px-2 py-1 text-sm text-red-700"
                      aria-label={t('leagues.delete', { name: league.name })}
                      onClick={() => window.confirm(t('leagues.deleteConfirm', { name: league.name })) && remove.mutate(league.id)}
                    >
                      ✕
                    </button>
                  )}
                </div>
              )}
            </li>
          ))}
        </ul>
        {(update.isError || remove.isError) && <Notice tone="error">{errorText(update.error ?? remove.error, t('errors.generic'))}</Notice>}
      </Card>

      <AddLeague leagues={leagues.data!} onAdded={done} />
    </div>
  );
}

function EditLeague({ league, onSave, onCancel }: { league: League; onSave: (body: { name: string; level: number }) => void; onCancel: () => void }) {
  const { t } = useTranslation();
  const [name, setName] = useState(league.name);
  const [level, setLevel] = useState(String(league.level));

  return (
    <form
      className="space-y-2"
      onSubmit={(e) => {
        e.preventDefault();
        onSave({ name, level: Number(level) });
      }}
    >
      <div className="grid grid-cols-[1fr_5rem] gap-2">
        <Field label={t('leagues.name')} required maxLength={80} value={name} onChange={(e) => setName(e.target.value)} />
        <Field label={t('leagues.level')} type="number" min={1} max={30} required inputMode="numeric" value={level} onChange={(e) => setLevel(e.target.value)} />
      </div>
      <div className="flex gap-2">
        <Button type="submit">{t('common.save')}</Button>
        <Button variant="secondary" onClick={onCancel}>{t('common.cancel')}</Button>
      </div>
    </form>
  );
}

function AddLeague({ leagues, onAdded }: { leagues: League[]; onAdded: (result: { data: League[] }) => void }) {
  const { t } = useTranslation();
  const tp = useTenantPath();
  const [name, setName] = useState('');
  const [parent, setParent] = useState('');
  const [level, setLevel] = useState('');

  const create = useMutation({
    mutationFn: () => post<{ data: League[] }>(tp('leagues'), { name, parent_id: parent || null, ...(level ? { level: Number(level) } : {}) }),
    onSuccess: (result) => {
      setName('');
      setLevel('');
      onAdded(result);
    },
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    create.mutate();
  };

  return (
    <Card title={t('leagues.add')}>
      <form onSubmit={submit} className="space-y-3">
        <Field label={t('leagues.name')} required maxLength={80} value={name} onChange={(e) => setName(e.target.value)} placeholder={t('leagues.namePlaceholder')} />
        <Select label={t('leagues.parent')} value={parent} onChange={(e) => setParent(e.target.value)}>
          <option value="">{t('leagues.noParent')}</option>
          {leagues.map((l) => <option key={l.id} value={l.id}>{'– '.repeat(l.depth)}{l.name}</option>)}
        </Select>
        <Field label={t('leagues.level')} type="number" min={1} max={30} inputMode="numeric" value={level} onChange={(e) => setLevel(e.target.value)} hint={t('leagues.levelHint')} />
        {create.isError && <Notice tone="error">{errorText(create.error, t('errors.generic'))}</Notice>}
        <Button type="submit" disabled={create.isPending}>{t('common.add')}</Button>
      </form>
    </Card>
  );
}
