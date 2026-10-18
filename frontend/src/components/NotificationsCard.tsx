import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { api, put, type NotificationPreference } from '../lib/api';
import { disablePush, enablePush, isIos, pushState, type PushState } from '../lib/push';
import { useTenantSlug } from '../lib/queries';
import { Button, Card, Notice } from './ui';

/** Push on this device, plus push/e-mail per category for this department. */
export function NotificationsCard() {
  const { t } = useTranslation();
  const slug = useTenantSlug();
  const queryClient = useQueryClient();
  const [state, setState] = useState<PushState | null>(null);
  const [busy, setBusy] = useState(false);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    pushState().then(setState, () => setState('unsupported'));
  }, []);

  const key = ['notification-preferences', slug];
  const preferences = useQuery({
    queryKey: key,
    queryFn: async () => (await api<{ data: NotificationPreference[] }>(`/t/${slug}/notification-preferences`)).data,
  });
  const save = useMutation({
    mutationFn: (rows: NotificationPreference[]) => put<{ data: NotificationPreference[] }>(`/t/${slug}/notification-preferences`, { preferences: rows }),
    onSuccess: ({ data }) => queryClient.setQueryData(key, data),
    onError: () => queryClient.invalidateQueries({ queryKey: key }),
  });

  const toggleDevice = async () => {
    setBusy(true);
    setFailed(false);
    try {
      setState(state === 'enabled' ? await disablePush() : await enablePush());
    } catch {
      setFailed(true);
    } finally {
      setBusy(false);
    }
  };

  const change = (category: string, channel: 'push' | 'email', value: boolean) => {
    if (!preferences.data) return;
    const rows = preferences.data.map((row) => (row.category === category ? { ...row, [channel]: value } : row));
    // Shown right away (controlled checkbox); the server answer confirms it.
    queryClient.setQueryData(key, rows);
    save.mutate(rows);
  };

  return (
    <Card title={t('notifications.title')}>
      <div className="space-y-3">
        {state === 'needs-install' && <Notice>{t('notifications.iosInstall')}</Notice>}
        {state === 'unsupported' && <Notice>{t(isIos() ? 'notifications.iosTooOld' : 'notifications.unsupported')}</Notice>}
        {state === 'denied' && <Notice tone="error">{t('notifications.denied')}</Notice>}
        {(state === 'enabled' || state === 'disabled') && (
          <div className="flex items-center justify-between gap-3">
            <p className="text-sm">{t(state === 'enabled' ? 'notifications.deviceOn' : 'notifications.deviceOff')}</p>
            <Button variant={state === 'enabled' ? 'secondary' : 'primary'} onClick={toggleDevice} disabled={busy}>
              {t(state === 'enabled' ? 'notifications.disable' : 'notifications.enable')}
            </Button>
          </div>
        )}
        {failed && <Notice tone="error">{t('notifications.failed')}</Notice>}

        {preferences.data && (
          <table className="w-full text-sm">
            <thead>
              <tr className="text-left text-xs text-slate-500">
                <th className="py-1 font-normal">{t('notifications.category')}</th>
                <th className="w-16 py-1 text-center font-normal">{t('notifications.push')}</th>
                <th className="w-16 py-1 text-center font-normal">{t('notifications.email')}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {preferences.data.map((row) => {
                const label = t(`notifications.categories.${row.category}`);
                return (
                  <tr key={row.category}>
                    <td className="py-2 pr-2">{label}</td>
                    {(['push', 'email'] as const).map((channel) => (
                      <td key={channel} className="py-2 text-center">
                        <input
                          type="checkbox"
                          className="size-5 accent-brand-700"
                          aria-label={`${label}: ${t(`notifications.${channel}`)}`}
                          checked={row[channel]}
                          onChange={(e) => change(row.category, channel, e.target.checked)}
                        />
                      </td>
                    ))}
                  </tr>
                );
              })}
            </tbody>
          </table>
        )}
        <p className="text-xs text-slate-500">{t('notifications.hint')}</p>
      </div>
    </Card>
  );
}
