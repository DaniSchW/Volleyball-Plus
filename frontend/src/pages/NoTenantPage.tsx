import { useTranslation } from 'react-i18next';

export function NoTenantPage() {
  const { t } = useTranslation();

  return (
    <div className="mx-auto flex min-h-dvh max-w-md flex-col items-center justify-center gap-3 p-6 text-center">
      <h1 className="text-2xl font-semibold">{t('app.name')}</h1>
      <p className="text-slate-600">{t('tenant.choose')}</p>
    </div>
  );
}
