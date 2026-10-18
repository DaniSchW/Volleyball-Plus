import { useEffect, useId, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { NavLink } from 'react-router';
import { useQueryClient } from '@tanstack/react-query';
import { patch } from '../lib/api';
import { changeLanguage, LANGUAGES, type Language } from '../i18n';

/** Sections that follow in later phases (shown with a placeholder). */
export const PLACEHOLDER_SECTIONS = ['chat', 'cash', 'halls'] as const;
export type MenuSection = (typeof PLACEHOLDER_SECTIONS)[number] | 'events' | 'teams' | 'polls' | 'tasks' | 'stats' | 'profile' | 'members';

const MENU: MenuSection[] = ['events', 'teams', 'chat', 'polls', 'tasks', 'stats', 'cash', 'halls', 'profile'];

interface Props {
  tenantSlug: string;
  tenantName: string;
  logoUrl: string | null;
  isAdmin: boolean;
}

/**
 * Top bar with the department name and a drop-down menu at the top right –
 * deliberately no bottom tab bar, so the content keeps the screen space.
 */
export function AppHeader({ tenantSlug, tenantName, logoUrl, isAdmin }: Props) {
  const { t, i18n } = useTranslation();
  const queryClient = useQueryClient();
  const [open, setOpen] = useState(false);
  const menuId = useId();
  const containerRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;

    const onPointerDown = (event: PointerEvent) => {
      if (!containerRef.current?.contains(event.target as Node)) setOpen(false);
    };
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setOpen(false);
    };

    document.addEventListener('pointerdown', onPointerDown);
    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.removeEventListener('pointerdown', onPointerDown);
      document.removeEventListener('keydown', onKeyDown);
    };
  }, [open]);

  return (
    <header className="sticky top-0 z-20 border-b border-slate-200 bg-white/95 pt-[env(safe-area-inset-top)] backdrop-blur">
      <div ref={containerRef} className="relative mx-auto flex h-14 max-w-3xl items-center justify-between px-4">
        <NavLink to={`/${tenantSlug}`} className="flex min-w-0 items-center gap-2 text-base font-semibold text-slate-900">
          {logoUrl && <img src={logoUrl} alt="" className="size-8 shrink-0 object-contain" />}
          <span className="truncate">{tenantName}</span>
        </NavLink>

        <button
          type="button"
          className="-mr-2 rounded-lg p-2 text-slate-700 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-brand-600"
          aria-expanded={open}
          aria-controls={menuId}
          aria-label={open ? t('menu.close') : t('menu.open')}
          onClick={() => setOpen((value) => !value)}
        >
          <svg viewBox="0 0 24 24" className="size-6" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
            {open ? <path d="M6 6l12 12M18 6L6 18" /> : <path d="M4 7h16M4 12h16M4 17h16" />}
          </svg>
        </button>

        {open && (
          <nav
            id={menuId}
            className="absolute right-2 top-full mt-1 w-64 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-lg"
          >
            <ul className="py-1">
              {[...MENU, ...(isAdmin ? (['members'] as MenuSection[]) : [])].map((section) => (
                <li key={section}>
                  <NavLink
                    to={`/${tenantSlug}/${section}`}
                    onClick={() => setOpen(false)}
                    className={({ isActive }) =>
                      `block px-4 py-3 text-sm ${isActive ? 'bg-brand-50 font-medium text-brand-700' : 'text-slate-700 hover:bg-slate-50'}`
                    }
                  >
                    {t(`menu.${section}`)}
                  </NavLink>
                </li>
              ))}
            </ul>
            <div className="border-t border-slate-100 px-4 py-3">
              <label className="flex items-center justify-between gap-3 text-sm text-slate-600">
                {t('menu.language')}
                <select
                  className="rounded-md border border-slate-300 bg-white px-2 py-1 text-sm"
                  value={i18n.resolvedLanguage}
                  onChange={(event) => {
                    const locale = event.target.value as Language;
                    changeLanguage(locale);
                    // Also store it in the account, so mails and other devices use it.
                    void patch('/me', { locale }).then(() => queryClient.invalidateQueries({ queryKey: ['account'] }));
                  }}
                >
                  {LANGUAGES.map((language) => (
                    <option key={language} value={language}>
                      {t(`languages.${language}`)}
                    </option>
                  ))}
                </select>
              </label>
            </div>
          </nav>
        )}
      </div>
    </header>
  );
}
