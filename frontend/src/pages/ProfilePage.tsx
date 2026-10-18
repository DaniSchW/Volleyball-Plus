import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router';
import { api, del, post, type SessionInfo } from '../lib/api';
import { useAccount, useTenantMe, useTenantSlug } from '../lib/queries';
import { Button, Card, Loading, PageTitle } from '../components/ui';
import { NotificationsCard } from '../components/NotificationsCard';
import { forgetPushOnLogout } from '../lib/push';
import { ShareLink } from './TeamPage';

/** Own account: profiles (own and children's), devices, logout. */
export function ProfilePage() {
  const { t } = useTranslation();
  const slug = useTenantSlug();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const account = useAccount();
  const me = useTenantMe();
  const sessions = useQuery({ queryKey: ['sessions'], queryFn: async () => (await api<{ data: SessionInfo[] }>('/me/sessions')).data });

  const revoke = useMutation({
    mutationFn: (id: string) => del(`/me/sessions/${id}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['sessions'] }),
  });
  const logout = useMutation({
    mutationFn: async () => {
      await forgetPushOnLogout();
      return post('/auth/logout');
    },
    onSuccess: () => {
      queryClient.clear();
      navigate(`/${slug}/login`, { replace: true });
    },
  });

  if (me.isPending || account.isPending) return <Loading />;

  return (
    <div className="space-y-4">
      <PageTitle>{t('menu.profile')}</PageTitle>

      <Card title={t('profile.myProfiles')}>
        <ul className="divide-y divide-slate-100">
          {me.data?.persons.map((person) => (
            <li key={person.id}>
              <Link to={`/${slug}/persons/${person.id}`} className="flex min-h-12 items-center justify-between gap-3">
                <span>
                  <span className="font-medium">{person.first_name} {person.last_name}</span>
                  <span className="block text-xs text-slate-500">
                    {person.relation === 'guardian' ? t('profile.child') : t('profile.own')}
                    {person.teams.length > 0 && ` · ${person.teams.map((team) => team.name).join(', ')}`}
                  </span>
                </span>
                <span aria-hidden="true" className="text-slate-400">›</span>
              </Link>
            </li>
          ))}
        </ul>
        {me.data?.persons.length === 0 && <p className="text-sm text-slate-500">{t('profile.noProfiles')}</p>}
      </Card>

      <NotificationsCard />

      <CalendarCard />

      <Card title={t('profile.devices')}>
        <p className="mb-2 text-xs text-slate-500">{t('profile.devicesHint', { email: account.data?.email })}</p>
        <ul className="divide-y divide-slate-100">
          {sessions.data?.map((session) => (
            <li key={session.id} className="flex items-center justify-between gap-3 py-2 text-sm">
              <span className="min-w-0">
                <span className="block truncate">{session.user_agent ?? '–'}</span>
                <span className="text-xs text-slate-500">
                  {session.current ? t('profile.thisDevice') : t('profile.lastSeen', { date: new Date(session.last_seen_at).toLocaleDateString() })}
                </span>
              </span>
              {!session.current && (
                <Button variant="secondary" onClick={() => revoke.mutate(session.id)}>{t('profile.logoutDevice')}</Button>
              )}
            </li>
          ))}
        </ul>
      </Card>

      <Button variant="danger" className="w-full" onClick={() => logout.mutate()} disabled={logout.isPending}>
        {t('profile.logout')}
      </Button>
    </div>
  );
}

/** Free calendar sync: subscribe to the secret ICS address in any calendar app. */
function CalendarCard() {
  const { t } = useTranslation();
  const slug = useTenantSlug();
  const queryClient = useQueryClient();
  const feed = useQuery({ queryKey: ['calendar', slug], queryFn: async () => (await api<{ data: { url: string } }>(`/t/${slug}/calendar`)).data });
  const reset = useMutation({
    mutationFn: () => post<{ data: { url: string } }>(`/t/${slug}/calendar/reset`),
    onSuccess: ({ data }) => queryClient.setQueryData(['calendar', slug], data),
  });

  return (
    <Card title={t('calendar.title')}>
      <p className="mb-2 text-xs text-slate-500">{t('calendar.hint')}</p>
      {feed.data && (
        <>
          <ShareLink url={feed.data.url} title={t('calendar.title')} />
          <a href={feed.data.url.replace(/^https?:/, 'webcal:')} className="mt-2 inline-block text-sm text-brand-700 underline">{t('calendar.subscribe')}</a>
          <button type="button" className="mt-2 block text-xs text-red-700 underline" onClick={() => window.confirm(t('calendar.resetConfirm')) && reset.mutate()}>
            {t('calendar.reset')}
          </button>
        </>
      )}
    </Card>
  );
}
