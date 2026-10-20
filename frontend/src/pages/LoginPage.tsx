import { LegalLinks } from '../components/LegalLinks';
import { useState, type FormEvent } from 'react';
import { useMutation } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { errorText, post } from '../lib/api';
import { useTenant, useTenantSlug } from '../lib/queries';
import { Button, Field, Notice } from '../components/ui';

/** Login without password: enter the address, get a one-time link. */
export function LoginPage() {
  const { t } = useTranslation();
  const slug = useTenantSlug();
  const tenant = useTenant();
  const [email, setEmail] = useState('');

  const request = useMutation({
    mutationFn: () => post<{ message: string }>(`/t/${slug}/auth/login`, { email }),
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    request.mutate();
  };

  return (
    <div className="mx-auto flex min-h-dvh max-w-sm flex-col justify-center gap-6 p-4">
      <div className="text-center">
        <p className="text-sm text-slate-500">{t('app.name')}</p>
        <h1 className="text-2xl font-semibold">{tenant.data?.name ?? '…'}</h1>
      </div>

      {request.isSuccess ? (
        <Notice tone="success">{t('login.sent')}</Notice>
      ) : (
        <form onSubmit={submit} className="space-y-4 rounded-xl border border-slate-200 bg-white p-4">
          <p className="text-sm text-slate-600">{t('login.intro')}</p>
          <Field label={t('login.email')} type="email" autoComplete="email" required value={email} onChange={(e) => setEmail(e.target.value)} />
          {request.isError && <Notice tone="error">{errorText(request.error, t('errors.generic'))}</Notice>}
          <Button type="submit" className="w-full" disabled={request.isPending}>
            {t('login.submit')}
          </Button>
        </form>
      )}
      <p className="text-center text-xs text-slate-500">{t('login.inviteOnly')}</p>
      <LegalLinks />
    </div>
  );
}
