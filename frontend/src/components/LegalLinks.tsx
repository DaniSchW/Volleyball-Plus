import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';

/** Impressum · Datenschutz · AGB – on every public page and in the menu. */
export function LegalLinks({ className = '', onClick }: { className?: string; onClick?: () => void }) {
  const { t } = useTranslation();
  return (
    <nav aria-label={t('legal.links')} className={`flex flex-wrap justify-center gap-x-4 gap-y-1 text-xs text-slate-500 ${className}`}>
      <Link to="/impressum" onClick={onClick} className="underline-offset-2 hover:underline">{t('legal.imprint')}</Link>
      <Link to="/datenschutz" onClick={onClick} className="underline-offset-2 hover:underline">{t('legal.privacy')}</Link>
      <Link to="/agb" onClick={onClick} className="underline-offset-2 hover:underline">{t('legal.terms')}</Link>
    </nav>
  );
}
