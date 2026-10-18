import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { api, type Team } from '../lib/api';
import { useTenantMe, useTenantPath, useTenantSlug } from '../lib/queries';
import { Loading, Notice, PageTitle } from '../components/ui';

/** Entry to the statistics: one per team (rates and tasks live in the team). */
export function StatsPage() {
  const { t } = useTranslation();
  const slug = useTenantSlug();
  const tp = useTenantPath();
  const me = useTenantMe();
  const teams = useQuery({ queryKey: ['teams', slug], queryFn: async () => (await api<{ data: Team[] }>(tp('teams'))).data });

  if (teams.isPending || me.isPending) return <Loading />;

  const mine = new Set(me.data?.persons.flatMap((p) => p.teams.map((team) => team.id)));
  const list = (teams.data ?? []).filter((team) => team.can_see && (mine.has(team.id) || team.can_manage));

  return (
    <div className="space-y-4">
      <PageTitle>{t('menu.stats')}</PageTitle>
      <p className="text-sm text-slate-600">{t('stats.pageHint')}</p>
      {list.length === 0 && <Notice>{t('teams.none')}</Notice>}
      <ul className="divide-y divide-slate-100 overflow-hidden rounded-xl border border-slate-200 bg-white">
        {list.map((team) => (
          <li key={team.id}>
            <Link to={`/${slug}/teams/${team.id}?tab=statistics`} className="flex min-h-14 items-center justify-between px-4 hover:bg-slate-50">
              <span className="font-medium">{team.name}</span>
              <span aria-hidden="true" className="text-slate-400">›</span>
            </Link>
          </li>
        ))}
      </ul>
    </div>
  );
}
