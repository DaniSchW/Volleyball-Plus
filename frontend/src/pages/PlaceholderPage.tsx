import { useTranslation } from 'react-i18next';
import type { MenuSection } from '../components/AppHeader';

export function PlaceholderPage({ section }: { section: MenuSection }) {
  const { t } = useTranslation();

  return (
    <div>
      <h1 className="mb-3 text-lg font-semibold">{t(`menu.${section}`)}</h1>
      <p className="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
        {t('placeholder.comingSoon')}
      </p>
    </div>
  );
}
