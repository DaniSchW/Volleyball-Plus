import { useLogout } from '../lib/useLogout';
import { LegalLinks } from './LegalLinks';
import { ThemeSwitch } from './ThemeSwitch';
import { useEffect, useId, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { NavLink } from 'react-router';
import { useQueryClient } from '@tanstack/react-query';
import { patch } from '../lib/api';
import { changeLanguage, LANGUAGES, type Language } from '../i18n';

export type MenuSection = 'events' | 'teams' | 'chat' | 'polls' | 'tasks' | 'documents' | 'stats' | 'cash' | 'halls' | 'profile' | 'members' | 'activity';

const MENU: MenuSection[] = ['events', 'teams', 'chat', 'polls', 'tasks', 'documents', 'stats', 'cash', 'halls', 'profile'];

/** Icon and colour per menu entry – helps to find things quickly. */
const ICONS: Record<MenuSection, [string, string]> = {
  events: ['📅', 'bg-brand-100'],
  teams: ['👥', 'bg-emerald-100'],
  chat: ['💬', 'bg-sky-100'],
  polls: ['🗳️', 'bg-violet-100'],
  tasks: ['🤝', 'bg-amber-100'],
  documents: ['📁', 'bg-yellow-100'],
  stats: ['📊', 'bg-rose-100'],
  cash: ['💶', 'bg-lime-100'],
  halls: ['🏟️', 'bg-orange-100'],
  profile: ['👤', 'bg-slate-100'],
  members: ['🔑', 'bg-ball-300/40'],
  activity: ['🕒', 'bg-slate-100'],
};

interface Props {
  tenantSlug: string;
  tenantName: string;
  logoUrl: string | null;
  isAdmin: boolean;
  /** Sections this member cannot use (e.g. cash for players) – the server checks anyway. */
  hidden?: MenuSection[];
}

/**
 * Top bar with the department name and a drop-down menu (tiles) at the top right –
 * deliberately no bottom tab bar, so the content keeps the screen space.
 */
export function AppHeader({ tenantSlug, tenantName, logoUrl, isAdmin, hidden = [] }: Props) {
  const logout = useLogout(`/${tenantSlug}/login`);
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
    <header className="sticky top-0 z-20 bg-gradient-to-r from-brand-deep via-brand-solid to-brand-500 pt-[env(safe-area-inset-top)] text-white shadow-md">
      <div ref={containerRef} className="relative mx-auto flex h-14 max-w-3xl items-center justify-between px-4">
        <NavLink to={`/${tenantSlug}`} className="flex min-w-0 items-center gap-2 text-base font-semibold text-white">
          {logoUrl ? <img src={logoUrl} alt="" className="size-8 shrink-0 rounded-md bg-white object-contain p-0.5" /> : <img src="/icons/favicon.svg" alt="" className="size-8 shrink-0" />}
          <span className="truncate">{tenantName}</span>
        </NavLink>

        <button
          type="button"
          className="-mr-2 rounded-lg p-2 text-white hover:bg-white/15 focus-visible:outline-2 focus-visible:outline-white"
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
            className="absolute inset-x-2 top-full mt-1 max-h-[calc(100dvh-4.5rem-env(safe-area-inset-top))] overflow-y-auto overscroll-contain rounded-2xl border border-slate-200 bg-surface text-slate-900 shadow-xl sm:left-auto sm:w-96"
          >
            {/* Tiles in three columns – all sections fit on a phone screen without scrolling. */}
            <ul className="grid grid-cols-3 gap-1 p-2">
              {[...MENU, ...(isAdmin ? (['members', 'activity'] as MenuSection[]) : [])].filter((section) => !hidden.includes(section)).map((section) => (
                <li key={section}>
                  <NavLink
                    to={`/${tenantSlug}/${section}`}
                    onClick={() => setOpen(false)}
                    className={({ isActive }) =>
                      `flex h-full min-h-20 flex-col items-center justify-center gap-1.5 rounded-xl px-1 py-2 text-center text-xs leading-tight break-words hyphens-auto ${isActive ? 'bg-brand-50 font-semibold text-brand-700' : 'text-slate-700 hover:bg-slate-50'}`
                    }
                  >
                    <span aria-hidden="true" className={`flex size-9 items-center justify-center rounded-full text-lg ${ICONS[section][1]}`}>{ICONS[section][0]}</span>
                    {t(`menu.${section}`)}
                  </NavLink>
                </li>
              ))}
            </ul>
            <div className="space-y-3 border-t border-slate-100 px-4 py-3">
              <div className="flex items-center gap-2">
                <select
                  aria-label={t('menu.language')}
                  className="min-h-10 rounded-lg border border-slate-300 bg-surface px-2 text-sm"
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
                <div className="flex-1"><ThemeSwitch compact /></div>
              </div>
              <div className="flex items-center justify-between gap-3">
                <LegalLinks className="!justify-start" onClick={() => setOpen(false)} />
                <button
                  type="button"
                  className="flex shrink-0 items-center gap-2 rounded-lg px-2 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50"
                  disabled={logout.isPending}
                  onClick={() => logout.mutate()}
                >
                  <span aria-hidden="true">⏻</span>
                  {t('menu.logout')}
                </button>
              </div>
            </div>
          </nav>
        )}
      </div>
    </header>
  );
}
