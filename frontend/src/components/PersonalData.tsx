import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router';
import { del, errorText, type PersonDetail } from '../lib/api';
import { useTenantMe, useTenantPath, useTenantSlug } from '../lib/queries';
import { Button, Card, Field, Notice } from './ui';

/** DSGVO: download everything stored about the person, or delete the profile. */
export function PersonalData({ person }: { person: PersonDetail }) {
  const { t } = useTranslation();
  const slug = useTenantSlug();
  const tp = useTenantPath();
  const me = useTenantMe();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [confirm, setConfirm] = useState('');
  const own = me.data?.persons.some((p) => p.id === person.id && p.relation === 'self') ?? false;

  const remove = useMutation({
    mutationFn: () => del(tp(`persons/${person.id}`)),
    onSuccess: () => {
      if (own) {
        // The own login no longer has access to the department.
        window.location.assign(`/${slug}/login`);
        return;
      }
      void queryClient.invalidateQueries();
      navigate(`/${slug}`, { replace: true });
    },
  });

  return (
    <Card title={t('privacy.title')}>
      <div className="space-y-4">
        <div>
          <p className="mb-2 text-sm text-slate-600">{t('privacy.exportHint')}</p>
          <a
            href={`/api/v1${tp(`persons/${person.id}/export`)}`}
            download
            className="inline-flex min-h-11 items-center rounded-lg border border-slate-300 bg-surface px-4 text-sm font-medium"
          >
            ⬇️ {t('privacy.export')}
          </a>
        </div>

        <details className="rounded-lg border border-red-200 p-3">
          <summary className="cursor-pointer text-sm font-medium text-red-700">{own ? t('privacy.deleteOwn') : t('privacy.delete', { name: person.first_name })}</summary>
          <div className="mt-3 space-y-3">
            <p className="text-sm text-slate-700">{own ? t('privacy.deleteOwnHint') : t('privacy.deleteHint')}</p>
            <Field
              label={t('privacy.confirmLabel', { name: person.first_name })}
              value={confirm}
              autoComplete="off"
              onChange={(e) => setConfirm(e.target.value)}
            />
            {remove.isError && <Notice tone="error">{errorText(remove.error, t('errors.generic'))}</Notice>}
            <Button variant="danger" disabled={confirm.trim() !== person.first_name || remove.isPending} onClick={() => remove.mutate()}>
              {t('privacy.deleteNow')}
            </Button>
          </div>
        </details>
      </div>
    </Card>
  );
}
