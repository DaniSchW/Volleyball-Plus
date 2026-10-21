import { useTranslation } from 'react-i18next';
import { setTheme, useTheme, type ThemeChoice } from '../lib/theme';

const CHOICES: [ThemeChoice, string][] = [['auto', '📱'], ['light', '☀️'], ['dark', '🌙']];

/** Automatic / light / dark – stored on this device. Compact: icons only (menu). */
export function ThemeSwitch({ compact = false }: { compact?: boolean }) {
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
          aria-label={compact ? t(`theme.${value}`) : undefined}
          title={compact ? t(`theme.${value}`) : undefined}
          className={`flex ${compact ? 'min-h-8' : 'min-h-10'} items-center justify-center gap-1.5 rounded-lg text-sm font-medium transition ${theme === value ? 'bg-surface text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'}`}
        >
          <span aria-hidden="true">{icon}</span>
          {!compact && t(`theme.${value}`)}
        </button>
      ))}
    </div>
  );
}
