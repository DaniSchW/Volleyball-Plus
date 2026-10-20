import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { api, del, errorText, post, type PlatformClub } from '../lib/api';
import { formatDateTime } from '../lib/dates';
import { useAccount } from '../lib/queries';
import { Button, Card, Loading, Notice } from '../components/ui';
import { PlatformAccounts } from '../components/platform/PlatformAccounts';
import { LegalForm } from '../components/platform/LegalForm';

/**
 * Platform admins: approve clubs that registered, suspend or reactivate
 * clubs. Shows the clubs, never their contents.
 */
export function PlatformPage() {
  const { t } = useTranslation();
  const account = useAccount();
  const queryClient = useQueryClient();
  const [tab, setTab] = useState<'clubs' | 'accounts' | 'legal'>('clubs');
  const clubs = useQuery({
    queryKey: ['platform-clubs'],
    queryFn: async () => (await api<{ data: PlatformClub[] }>('/platform/clubs')).data,
    enabled: !!account.data?.is_platform_admin,
  });
  const done = ({ data }: { data: PlatformClub[] }) => queryClient.setQueryData(['platform-clubs'], data);
  const action = useMutation({
    mutationFn: ({ id, kind }: { id: string; kind: 'approve' | 'suspend' | 'reactivate' | 'reject' }) =>
      kind === 'reject' ? del<{ data: PlatformClub[] }>(`/platform/clubs/${id}`) : post<{ data: PlatformClub[] }>(`/platform/clubs/${id}/${kind}`),
    onSuccess: done,
  });
  // Deleting a club for good: only suspended ones, the short name has to be typed again.
  const destroy = useMutation({
    mutationFn: ({ id, confirm }: { id: string; confirm: string }) => post<{ data: PlatformClub[] }>(`/platform/clubs/${id}/delete`, { confirm }),
    onSuccess: done,
  });

  if (account.isPending || clubs.isPending) return <Loading />;
  if (!account.data?.is_platform_admin) return <Notice tone="error">{t('tenant.noAccess')}</Notice>;
  if (clubs.isError) return <Notice tone="error">{errorText(clubs.error, t('errors.generic'))}</Notice>;

  const groups = (['pending', 'active', 'suspended'] as const).map((status) => ({ status, list: clubs.data.filter((c) => c.status === status) }));

  return (
    <div className="mx-auto max-w-3xl space-y-4 p-4">
      <div>
        <Link to="/" className="text-sm text-brand-700">‹ {t('app.name')}</Link>
        <h1 className="mt-2 text-xl font-semibold">{t('platform.title')}</h1>
        <p className="text-sm text-slate-600">{t('platform.hint')}</p>
      </div>
      {account.data.is_superadmin && (
        <div role="tablist" className="flex gap-1 rounded-lg bg-slate-100 p-1">
          {(['clubs', 'accounts', 'legal'] as const).map((value) => (
            <button
              key={value}
              role="tab"
              type="button"
              aria-selected={tab === value}
              onClick={() => setTab(value)}
              className={`min-h-10 flex-1 rounded-md text-sm ${tab === value ? 'bg-brand-700 font-medium text-white shadow-sm' : 'text-slate-600'}`}
            >
              {t(`platform.tabs.${value}`)}
            </button>
          ))}
        </div>
      )}
      {tab === 'accounts' && account.data.is_superadmin && <PlatformAccounts ownEmail={account.data.email} />}
      {tab === 'legal' && account.data.is_superadmin && <LegalForm />}
      {tab === 'clubs' && action.isError && <Notice tone="error">{errorText(action.error, t('errors.generic'))}</Notice>}
      {destroy.isError && <Notice tone="error">{errorText(destroy.error, t('errors.generic'))}</Notice>}

      {tab === 'clubs' && groups.map(({ status, list }) => (
        <Card key={status} title={`${t(`platform.status.${status}`)} (${list.length})`}>
          {list.length === 0 ? (
            <p className="text-sm text-slate-500">{t('platform.none')}</p>
          ) : (
            <ul className="-mx-4 divide-y divide-slate-100">
              {list.map((club) => (
                <li key={club.id} className="space-y-2 px-4 py-3">
                  <div>
                    <p className="font-medium">{club.name}{club.city && <span className="font-normal text-slate-500"> · {club.city}</span>}</p>
                    <p className="text-xs text-slate-500">
                      /{club.slug} · {t('platform.counts', { members: club.members, teams: club.teams })}
                      {club.created_at && ` · ${t('platform.registeredAt', { date: formatDateTime(club.created_at) })}`}
                    </p>
                    {club.contact_name && (
                      <p className="text-sm">
                        {club.contact_name}
                        {club.contact_email && <> · <a className="text-brand-700 underline" href={`mailto:${club.contact_email}`}>{club.contact_email}</a></>}
                      </p>
                    )}
                    {club.note && <p className="whitespace-pre-line text-sm text-slate-600">„{club.note}“</p>}
                  </div>
                  <div className="flex flex-wrap gap-2">
                    {status === 'pending' && (
                      <>
                        <Button onClick={() => action.mutate({ id: club.id, kind: 'approve' })} disabled={action.isPending}>{t('platform.approve')}</Button>
                        <Button
                          variant="danger"
                          disabled={action.isPending}
                          onClick={() => window.confirm(t('platform.rejectConfirm', { name: club.name })) && action.mutate({ id: club.id, kind: 'reject' })}
                        >
                          {t('platform.reject')}
                        </Button>
                      </>
                    )}
                    {status === 'active' && (
                      <Button
                        variant="secondary"
                        disabled={action.isPending}
                        onClick={() => window.confirm(t('platform.suspendConfirm', { name: club.name })) && action.mutate({ id: club.id, kind: 'suspend' })}
                      >
                        {t('platform.suspend')}
                      </Button>
                    )}
                    {status === 'suspended' && (
                      <Button variant="secondary" disabled={action.isPending} onClick={() => action.mutate({ id: club.id, kind: 'reactivate' })}>{t('platform.reactivate')}</Button>
                    )}
                    {status === 'suspended' && (
                      <Button
                        variant="danger"
                        disabled={destroy.isPending}
                        onClick={() => {
                          const confirm = window.prompt(t('platform.deleteConfirm', { name: club.name, slug: club.slug }));
                          if (confirm !== null) destroy.mutate({ id: club.id, confirm: confirm.trim() });
                        }}
                      >
                        {t('platform.delete')}
                      </Button>
                    )}
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Card>
      ))}
    </div>
  );
}
