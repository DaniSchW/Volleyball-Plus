import { useState, type FormEvent } from 'react';
import { useMutation } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link, Navigate } from 'react-router';
import { errorText, post } from '../lib/api';
import { useAccount } from '../lib/queries';
import { Button, Field, Loading, Notice } from '../components/ui';
import { LegalLinks } from '../components/LegalLinks';
import { useLogout } from '../lib/useLogout';

/**
 * Start page of the platform: logged in → the account's clubs (one club:
 * straight there); otherwise login by e-mail and registration of a club.
 */
export function NoTenantPage() {
  const { t } = useTranslation();
  const account = useAccount();
  const logout = useLogout();

  if (account.isPending) return <Loading />;

  const tenants = account.data?.tenants ?? [];
  if (account.data && tenants.length === 1 && !account.data.is_platform_admin) return <Navigate to={`/${tenants[0].slug}`} replace />;

  return (
    <div className="mx-auto flex min-h-dvh max-w-md flex-col justify-center gap-6 p-6">
      <div className="text-center">
        <img src="/icons/favicon.svg" alt="" className="mx-auto mb-3 size-14" />
        <h1 className="text-2xl font-semibold">{t('app.name')}</h1>
        <p className="mt-1 text-sm text-slate-600">{t('start.tagline')}</p>
      </div>

      {account.data ? (
        <div className="space-y-3">
          {tenants.length > 0 ? (
            <>
              <h2 className="text-sm font-semibold text-slate-700">{t('start.yourClubs')}</h2>
              <ul className="space-y-2">
                {tenants.map((tenant) => (
                  <li key={tenant.slug}>
                    <Link to={`/${tenant.slug}`} className="block rounded-xl border border-slate-200 bg-white p-4 font-medium hover:bg-slate-50">
                      {tenant.name}
                    </Link>
                  </li>
                ))}
              </ul>
            </>
          ) : (
            <Notice>{t('start.noClub')}</Notice>
          )}
          {account.data.is_platform_admin && (
            <Link to="/platform" className="block rounded-xl border border-brand-600 bg-brand-50 p-4 font-medium text-brand-700">
              ⚙️ {t('platform.title')}
            </Link>
          )}
          <button type="button" className="w-full text-center text-sm text-slate-500 underline" onClick={() => logout.mutate()}>
            {t('start.logout', { email: account.data.email })}
          </button>
        </div>
      ) : (
        <StartLogin />
      )}

      <div className="rounded-xl border border-slate-200 bg-white p-4 text-center">
        <p className="text-sm text-slate-600">{t('start.registerHint')}</p>
        <Link to="/register" className="mt-3 inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-4 text-sm font-medium">
          {t('start.register')}
        </Link>
      </div>
      <LegalLinks />
    </div>
  );
}

function StartLogin() {
  const { t } = useTranslation();
  const [email, setEmail] = useState('');
  const request = useMutation({ mutationFn: () => post('/auth/login', { email }) });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    request.mutate();
  };

  if (request.isSuccess) return <Notice tone="success">{t('login.sent')}</Notice>;

  return (
    <form onSubmit={submit} className="space-y-4 rounded-xl border border-slate-200 bg-white p-4">
      <p className="text-sm text-slate-600">{t('start.loginIntro')}</p>
      <Field label={t('login.email')} type="email" autoComplete="email" required value={email} onChange={(e) => setEmail(e.target.value)} />
      {request.isError && <Notice tone="error">{errorText(request.error, t('errors.generic'))}</Notice>}
      <Button type="submit" className="w-full" disabled={request.isPending}>{t('login.submit')}</Button>
    </form>
  );
}
