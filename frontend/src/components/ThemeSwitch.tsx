import { useTranslation } from 'react-i18next';
import { setTheme, useTheme, type ThemeChoice } from '../lib/theme';

const CHOICES: [ThemeChoice, string][] = [['auto', '📱'], ['light', '☀️'], ['dark', '🌙']];

/** Automatic / light / dark – stored on this device. */
export function ThemeSwitch() {
  const { t } = useTranslation();
  const theme = useTheme();
  return (
    <div role="radiogroup" aria-label={t('theme.title')} className="grid grid-cols-3 gap-1 rounded-xl bg-slate-100 p-1">
      {CHOICES.map(([value, icon]) => (
        <button
          key={value}
          type="button"
          role="radio"
          aria-checked={theme === value}
          onClick={() => setTheme(value)}
          className={`flex min-h-10 items-center justify-center gap-1.5 rounded-lg text-sm font-medium transition ${theme === value ? 'bg-surface text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'}`}
        >
          <span aria-hidden="true">{icon}</span>
          {t(`theme.${value}`)}
        </button>
      ))}
    </div>
  );
}
