import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useInstall } from '../lib/install';

const KEY = 'vo.install.dismissed';
const AGAIN_AFTER_DAYS = 30;

function dismissedRecently(): boolean {
  try {
    const at = Number(localStorage.getItem(KEY) ?? 0);
    return at > 0 && Date.now() - at < AGAIN_AFTER_DAYS * 864e5;
  } catch {
    return false;
  }
}

/**
 * Invitation to install VolleyOrga as an app – a button that opens the
 * browser's install dialog, or on iPhone/iPad the two steps in Safari.
 * Hidden when already installed; "Later" hides it for 30 days.
 */
export function InstallApp({ always = false }: { always?: boolean }) {
  const { t } = useTranslation();
  const { mode, install } = useInstall();
  const [hidden, setHidden] = useState(() => !always && dismissedRecently());

  if (!mode || hidden) return null;
  const later = () => {
    try {
      localStorage.setItem(KEY, String(Date.now()));
    } catch {
      // private mode – just hide for now
    }
    setHidden(true);
  };

  return (
    <section className="vo-rise flex items-start gap-3 rounded-2xl border border-brand-100 bg-white p-4 shadow-sm" aria-labelledby="install-heading">
      <img src="/icons/icon-192.png" alt="" className="size-12 shrink-0 rounded-xl shadow-sm" />
      <div className="min-w-0 flex-1">
        <h2 id="install-heading" className="font-semibold">{t('install.title')}</h2>
        {mode === 'prompt' ? (
          <p className="text-sm text-slate-600">{t('install.text')}</p>
        ) : (
          <ol className="mt-1 list-decimal space-y-0.5 pl-5 text-sm text-slate-600">
            <li>{t('install.iosStep1')} <span aria-hidden="true" className="inline-block rounded border border-slate-300 px-1 text-xs">⬆︎</span></li>
            <li>{t('install.iosStep2')}</li>
          </ol>
        )}
        <div className="mt-3 flex flex-wrap gap-2">
          {mode === 'prompt' && (
            <button type="button" className="inline-flex min-h-10 items-center rounded-xl bg-gradient-to-b from-brand-600 to-brand-700 px-4 text-sm font-semibold text-white shadow-sm" onClick={() => void install()}>
              📲 {t('install.button')}
            </button>
          )}
          {!always && <button type="button" className="min-h-10 px-2 text-sm text-slate-500 underline" onClick={later}>{t('install.later')}</button>}
        </div>
      </div>
    </section>
  );
}
