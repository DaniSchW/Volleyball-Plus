import { useTranslation } from 'react-i18next';
import { Link, Navigate } from 'react-router';
import { useAccount } from '../lib/queries';
import { Loading } from '../components/ui';

/** Start without department in the URL: pick one of the account's departments. */
export function NoTenantPage() {
  const { t } = useTranslation();
  const account = useAccount();

  if (account.isPending) return <Loading />;

  const tenants = account.data?.tenants ?? [];
  if (tenants.length === 1) return <Navigate to={`/${tenants[0].slug}`} replace />;

  return (
    <div className="mx-auto flex min-h-dvh max-w-md flex-col items-center justify-center gap-4 p-6 text-center">
      <h1 className="text-2xl font-semibold">{t('app.name')}</h1>
      {tenants.length > 1 ? (
        <ul className="w-full space-y-2">
          {tenants.map((tenant) => (
            <li key={tenant.slug}>
              <Link to={`/${tenant.slug}`} className="block rounded-xl border border-slate-200 bg-white p-4 font-medium hover:bg-slate-50">
                {tenant.name}
              </Link>
            </li>
          ))}
        </ul>
      ) : (
        <p className="text-slate-600">{t('tenant.choose')}</p>
      )}
    </div>
  );
}
