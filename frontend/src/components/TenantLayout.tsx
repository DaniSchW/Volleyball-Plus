import { useTranslation } from 'react-i18next';
import { Outlet } from 'react-router';
import { ApiError } from '../lib/api';
import { useTenant } from '../lib/useTenant';
import { AppHeader } from './AppHeader';

export function TenantLayout() {
  const { t } = useTranslation();
  const { data: tenant, isPending, error } = useTenant();

  if (isPending) {
    return <p className="p-6 text-center text-slate-500">{t('tenant.loading')}</p>;
  }

  if (error || !tenant) {
    const notFound = error instanceof ApiError && error.status === 404;
    return <p className="p-6 text-center text-slate-600">{notFound ? t('tenant.notFound') : t('errors.generic')}</p>;
  }

  return (
    <div className="min-h-dvh">
      <AppHeader tenantSlug={tenant.slug} tenantName={tenant.name} />
      <main className="mx-auto max-w-3xl px-4 py-4 pb-[calc(1rem+env(safe-area-inset-bottom))]">
        <Outlet />
      </main>
    </div>
  );
}
