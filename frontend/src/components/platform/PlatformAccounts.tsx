import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { api, errorText, post, type PlatformAccount } from '../../lib/api';
import { formatDateTime } from '../../lib/dates';
import { Button, Card, Field, Loading, Notice } from '../ui';

type Filter = 'all' | 'inactive' | 'warned';
type Page = { data: PlatformAccount[]; meta: { total: number; page: number; last_page: number } };

/**
 * Superadmins: every account of the platform with clubs and last use.
 * Deleting asks for the address again; inactive accounts are cleaned up
 * by the cron (notice after two years, deletion 14 days later).
 */
export function PlatformAccounts({ ownEmail }: { ownEmail: string }) {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [search, setSearch] = useState('');
  const [filter, setFilter] = useState<Filter>('all');
  const [page, setPage] = useState(1);
  const [message, setMessage] = useState<string | null>(null);
  const params = new URLSearchParams({ filter, page: String(page), ...(search.trim() ? { search: search.trim() } : {}) }).toString();
  const accounts = useQuery({
    queryKey: ['platform-accounts', params],
    queryFn: () => api<Page>(`/platform/accounts?${params}`),
    placeholderData: (previous) => previous,
  });

  const remove = useMutation({
    mutationFn: ({ id, confirm }: { id: string; confirm: string }) => post<{ message: string }>(`/platform/accounts/${id}/delete`, { confirm }),
    onSuccess: (result) => {
      setMessage(result.message);
      void queryClient.invalidateQueries({ queryKey: ['platform-accounts'] });
    },
  });

  return (
    <div className="space-y-4">
      <p className="text-sm text-slate-600">{t('platform.accounts.hint')}</p>
      <Field label={t('platform.accounts.search')} type="search" value={search} onChange={(e) => { setSearch(e.target.value); setPage(1); }} />
      <div role="group" aria-label={t('platform.accounts.filter')} className="flex flex-wrap gap-2">
        {(['all', 'inactive', 'warned'] as const).map((value) => (
          <button
            key={value}
            type="button"
            aria-pressed={filter === value}
            onClick={() => { setFilter(value); setPage(1); }}
            className={`min-h-10 rounded-full border px-4 text-sm ${filter === value ? 'border-brand-700 bg-brand-700 text-white' : 'border-slate-300 bg-white text-slate-700'}`}
          >
            {t(`platform.accounts.filters.${value}`)}
          </button>
        ))}
      </div>
      {message && <Notice tone="success">{message}</Notice>}
      {remove.isError && <Notice tone="error">{errorText(remove.error, t('errors.generic'))}</Notice>}
      {accounts.isPending && <Loading />}
      {accounts.isError && <Notice tone="error">{errorText(accounts.error, t('errors.generic'))}</Notice>}
      {accounts.data && (
        <Card title={t('platform.accounts.title', { count: accounts.data.meta.total })}>
          {accounts.data.data.length === 0 ? (
            <p className="text-sm text-slate-500">{t('platform.accounts.none')}</p>
          ) : (
            <ul className="-mx-4 divide-y divide-slate-100">
              {accounts.data.data.map((a) => (
                <li key={a.id} className="flex flex-wrap items-start gap-3 px-4 py-3">
                  <div className="min-w-0 flex-1">
                    <p className="break-all font-medium">
                      {a.email}
                      {a.is_superadmin && <span className="ml-2 rounded bg-ball-300/50 px-1.5 text-xs">{t('platform.accounts.superadmin')}</span>}
                      {!a.is_superadmin && a.is_platform_admin && <span className="ml-2 rounded bg-slate-100 px-1.5 text-xs">{t('platform.accounts.platformAdmin')}</span>}
                    </p>
                    <p className="text-xs text-slate-500">
                      {a.clubs.length === 0 ? t('platform.accounts.noClub') : a.clubs.map((c) => `${c.name}${c.is_admin ? ` (${t('platform.accounts.admin')})` : ''}${c.active ? '' : ` – ${t('platform.accounts.disabled')}`}`).join(', ')}
                    </p>
                    <p className="text-xs text-slate-500">
                      {t('platform.accounts.lastActive', { date: a.last_active_at ? formatDateTime(a.last_active_at) : '–' })}
                    </p>
                    {a.deletion_due_at && (
                      <p className="text-xs font-medium text-red-700">⚠️ {t('platform.accounts.deletionDue', { date: formatDateTime(a.deletion_due_at) })}</p>
                    )}
                  </div>
                  {a.email !== ownEmail && !a.is_superadmin && (
                    <Button
                      variant="danger"
                      disabled={remove.isPending}
                      onClick={() => {
                        const confirm = window.prompt(t('platform.accounts.deleteConfirm', { email: a.email }));
                        if (confirm !== null) remove.mutate({ id: a.id, confirm });
                      }}
                    >
                      {t('platform.accounts.delete')}
                    </Button>
                  )}
                </li>
              ))}
            </ul>
          )}
          {accounts.data.meta.last_page > 1 && (
            <div className="mt-3 flex items-center justify-between gap-2 text-sm">
              <Button variant="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>‹</Button>
              <span>{t('platform.accounts.page', { page, of: accounts.data.meta.last_page })}</span>
              <Button variant="secondary" disabled={page >= accounts.data.meta.last_page} onClick={() => setPage(page + 1)}>›</Button>
            </div>
          )}
        </Card>
      )}
    </div>
  );
}
