import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { api, errorText, patch, type League, type Team } from '../../lib/api';
import { useTenantPath, useTenantSlug } from '../../lib/queries';
import { Button, Card, Field, Notice, Select, Toggle } from '../ui';

/** Admins: name, kind, youth, league and order within the league (hierarchy). */
export function TeamBaseData({ team }: { team: Team }) {
  const { t } = useTranslation();
  const slug = useTenantSlug();
  const tp = useTenantPath();
  const queryClient = useQueryClient();
  const leagues = useQuery({ queryKey: ['leagues', slug], queryFn: async () => (await api<{ data: League[] }>(tp('leagues'))).data });
  const [form, setForm] = useState({
    name: team.name,
    gender: team.gender,
    is_youth: team.is_youth,
    league_id: team.league?.id ?? '',
    rank_in_league: String(team.rank_in_league ?? 1),
  });

  const save = useMutation({
    mutationFn: () => patch<{ data: Team }>(tp(`teams/${team.id}`), {
      ...form,
      league_id: form.is_youth ? null : form.league_id || null,
      rank_in_league: Number(form.rank_in_league) || 1,
    }),
    onSuccess: ({ data }) => {
      queryClient.setQueryData(['team', team.id], data);
      void queryClient.invalidateQueries({ queryKey: ['teams', slug] });
    },
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    save.mutate();
  };

  return (
    <form onSubmit={submit}>
      <Card title={t('teamBase.title')}>
        <div className="space-y-3">
          <Field label={t('teams.name')} required maxLength={80} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
          <Select label={t('teams.genderLabel')} value={form.gender} onChange={(e) => setForm({ ...form, gender: e.target.value as Team['gender'] })}>
            {(['men', 'women', 'mixed'] as const).map((g) => <option key={g} value={g}>{t(`teams.gender.${g}`)}</option>)}
          </Select>
          <Toggle label={t('teams.youthLabel')} checked={form.is_youth} onChange={(v) => setForm({ ...form, is_youth: v })} />
          {!form.is_youth && (
            <div className="grid grid-cols-[1fr_6rem] gap-3">
              <Select label={t('teamBase.league')} value={form.league_id} onChange={(e) => setForm({ ...form, league_id: e.target.value })}>
                <option value="">{t('teamBase.noLeague')}</option>
                {leagues.data?.map((l) => <option key={l.id} value={l.id}>{'– '.repeat(l.depth)}{l.name}</option>)}
              </Select>
              <Field label={t('teamBase.rank')} type="number" min={1} max={99} inputMode="numeric" value={form.rank_in_league} onChange={(e) => setForm({ ...form, rank_in_league: e.target.value })} />
            </div>
          )}
          <p className="text-xs text-slate-500">
            {t('teamBase.hint')} <Link to={`/${slug}/leagues`} className="text-brand-700 underline">{t('leagues.manage')}</Link>
          </p>
          {save.isError && <Notice tone="error">{errorText(save.error, t('errors.generic'))}</Notice>}
          {save.isSuccess && <Notice tone="success">{t('common.saved')}</Notice>}
          <Button type="submit" disabled={save.isPending}>{t('common.save')}</Button>
        </div>
      </Card>
    </form>
  );
}
