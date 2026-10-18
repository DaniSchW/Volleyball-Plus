import { useTranslation } from 'react-i18next';
import type { RefereeLicense } from '../../lib/api';
import { formatDate } from '../../lib/dates';

/** Referee license with expiry: amber shortly before, red once expired. */
export function RefereeBadge({ license }: { license: RefereeLicense }) {
  const { t } = useTranslation();
  const tone = license.expired ? 'bg-red-100 text-red-800' : license.expires_soon ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-700';

  return (
    <span className={`inline-block rounded px-1.5 py-0.5 text-xs ${tone}`}>
      {t('referee.badge', { level: license.level, date: formatDate(license.valid_until) })}
      {license.expired && ` · ${t('referee.expired')}`}
    </span>
  );
}
