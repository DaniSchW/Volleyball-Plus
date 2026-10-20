import { useTranslation } from 'react-i18next';
import { Documents } from '../components/documents/Documents';
import { PageTitle } from '../components/ui';

/** All documents the member may see: for everybody and per team. */
export function DocumentsPage() {
  const { t } = useTranslation();
  return (
    <div className="space-y-4">
      <PageTitle>{t('menu.documents')}</PageTitle>
      <p className="text-sm text-slate-600">{t('documents.intro')}</p>
      <Documents />
    </div>
  );
}
