import { useTranslation } from 'react-i18next';
import { Select, Toggle } from '../ui';

export type EmailMode = 'none' | 'without_push' | 'all';

/** Push on/off and e-mail as alternative (only to those without push) or to everybody. */
export function NotifyChoice({ push, email, onPush, onEmail }: { push: boolean; email: EmailMode; onPush: (v: boolean) => void; onEmail: (v: EmailMode) => void }) {
  const { t } = useTranslation();
  return (
    <div className="space-y-2 rounded-lg bg-slate-50 p-3">
      <Toggle label={t('polls.notify.push')} checked={push} onChange={onPush} />
      <Select label={t('polls.notify.email')} value={email} onChange={(e) => onEmail(e.target.value as EmailMode)}>
        <option value="without_push">{t('polls.notify.emailWithoutPush')}</option>
        <option value="all">{t('polls.notify.emailAll')}</option>
        <option value="none">{t('polls.notify.emailNone')}</option>
      </Select>
      <p className="text-xs text-slate-500">{t('polls.notify.hint')}</p>
    </div>
  );
}
