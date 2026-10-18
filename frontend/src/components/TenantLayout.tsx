import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { Navigate, Outlet } from 'react-router';
import { ApiError } from '../lib/api';
import { changeLanguage, LANGUAGES, type Language } from '../i18n';
import { useAccount, useTenant, useTenantMe, useTenantSlug } from '../lib/queries';
import { AppHeader } from './AppHeader';
import { Loading, Notice } from './ui';

/** Frame of all department pages; only for logged-in members. */
export function TenantLayout() {
  const { t } = useTranslation();
  const slug = useTenantSlug();
  const tenant = useTenant();
  const account = useAccount();
  const me = useTenantMe();

  // The language chosen in the account wins over the browser's.
  const locale = account.data?.locale;
  useEffect(() => {
    if (locale && LANGUAGES.includes(locale as Language)) changeLanguage(locale as Language);
  }, [locale]);

  if (tenant.isPending || account.isPending || (account.data && me.isPending)) return <Loading />;

  if (tenant.isError) {
    const notFound = tenant.error instanceof ApiError && tenant.error.status === 404;
    return <div className="p-6"><Notice tone="error">{notFound ? t('tenant.notFound') : t('errors.generic')}</Notice></div>;
  }

  if (!account.data || (me.error instanceof ApiError && me.error.status === 401)) {
    return <Navigate to={`/${slug}/login`} replace />;
  }

  if (me.isError) {
    return <div className="p-6"><Notice tone="error">{me.error instanceof ApiError && me.error.status === 403 ? t('tenant.noAccess') : t('errors.generic')}</Notice></div>;
  }

  if (!me.data) return <Loading />;

  return (
    <div className="min-h-dvh">
      <AppHeader tenantSlug={slug} tenantName={tenant.data.name} logoUrl={tenant.data.logo_url} isAdmin={me.data.is_admin} />
      <main className="mx-auto max-w-3xl px-4 py-4 pb-[calc(1rem+env(safe-area-inset-bottom))]">
        <Outlet />
      </main>
    </div>
  );
}
