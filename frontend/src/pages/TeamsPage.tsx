import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { api, errorText, post, type Team } from '../lib/api';
import { useTenantMe, useTenantPath, useTenantSlug } from '../lib/queries';
import { Button, Card, Field, Loading, Notice, PageTitle, Select, Toggle } from '../components/ui';

export function TeamsPage() {
  const { t } = useTranslation();
  const slug = useTenantSlug();
  const tp = useTenantPath();
  const me = useTenantMe();
  const teams = useQuery({ queryKey: ['teams', slug], queryFn: async () => (await api<{ data: Team[] }>(tp('teams'))).data });

  if (teams.isPending) return <Loading />;
  if (teams.isError) return <Notice tone="error">{t('errors.generic')}</Notice>;

  const myTeamIds = new Set(me.data?.persons.flatMap((p) => p.teams.map((team) => team.id)));

  return (
    <div className="space-y-4">
      <PageTitle>{t('menu.teams')}</PageTitle>
      <ul className="divide-y divide-slate-100 overflow-hidden rounded-xl border border-slate-200 bg-white">
        {teams.data.map((team) => (
          <li key={team.id}>
            {team.can_see ? (
              <Link to={`/${slug}/teams/${team.id}`} className="flex min-h-14 items-center justify-between gap-3 px-4 hover:bg-slate-50">
                <TeamLabel team={team} mine={myTeamIds.has(team.id)} />
                <span aria-hidden="true" className="text-slate-400">›</span>
              </Link>
            ) : (
              <div className="flex min-h-14 items-center px-4 text-slate-500">
                <TeamLabel team={team} mine={false} />
              </div>
            )}
          </li>
        ))}
        {teams.data.length === 0 && <li className="p-4 text-sm text-slate-500">{t('teams.none')}</li>}
      </ul>
      {me.data?.is_admin && (
        <Link to={`/${slug}/leagues`} className="inline-flex min-h-11 items-center rounded-lg border border-slate-300 bg-white px-4 text-sm font-medium">
          {t('leagues.manage')}
        </Link>
      )}
      {me.data?.is_admin && <CreateTeam />}
    </div>
  );
}

function TeamLabel({ team, mine }: { team: Team; mine: boolean }) {
  const { t } = useTranslation();
  return (
    <span className="flex items-center gap-3">
      <span className="size-3 rounded-full" style={{ background: team.color ?? '#94a3b8' }} aria-hidden="true" />
      <span>
        <span className="font-medium">{team.name}</span>
        <span className="block text-xs text-slate-500">
          {t(`teams.gender.${team.gender}`)}
          {team.league_name ? ` · ${team.league_name}` : ''}
          {team.is_youth ? ` · ${t('teams.youth')}` : ''}
          {mine ? ` · ${t('teams.mine')}` : ''}
        </span>
      </span>
    </span>
  );
}

function CreateTeam() {
  const { t } = useTranslation();
  const slug = useTenantSlug();
  const tp = useTenantPath();
  const queryClient = useQueryClient();
  const [name, setName] = useState('');
  const [gender, setGender] = useState<Team['gender']>('men');
  const [isYouth, setIsYouth] = useState(false);

  const create = useMutation({
    mutationFn: () => post(tp('teams'), { name, gender, is_youth: isYouth }),
    onSuccess: () => {
      setName('');
      void queryClient.invalidateQueries({ queryKey: ['teams', slug] });
    },
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    create.mutate();
  };

  return (
    <Card title={t('teams.create')}>
      <form onSubmit={submit} className="space-y-3">
        <Field label={t('teams.name')} required value={name} onChange={(e) => setName(e.target.value)} />
        <Select label={t('teams.genderLabel')} value={gender} onChange={(e) => setGender(e.target.value as Team['gender'])}>
          {(['men', 'women', 'mixed'] as const).map((g) => (
            <option key={g} value={g}>{t(`teams.gender.${g}`)}</option>
          ))}
        </Select>
        <Toggle label={t('teams.youthLabel')} checked={isYouth} onChange={setIsYouth} />
        {create.isError && <Notice tone="error">{errorText(create.error, t('errors.generic'))}</Notice>}
        <Button type="submit" disabled={create.isPending}>{t('common.create')}</Button>
      </form>
    </Card>
  );
}
