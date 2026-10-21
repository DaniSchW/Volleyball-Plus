import { useSyncExternalStore } from 'react';

/** Light or dark design: follows the device unless chosen in the profile (stored per device). */
export type ThemeChoice = 'auto' | 'light' | 'dark';

const KEY = 'vo.theme';
const media = typeof window !== 'undefined' && window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
const listeners = new Set<() => void>();

function read(): ThemeChoice {
  try {
    const value = localStorage.getItem(KEY);
    return value === 'light' || value === 'dark' ? value : 'auto';
  } catch {
    return 'auto';
  }
}

let choice = read();

/** Sets html.dark / html.light and the colour of the status bar. */
function apply() {
  const dark = choice === 'dark' || (choice === 'auto' && !!media?.matches);
  const root = document.documentElement;
  root.classList.toggle('dark', dark);
  root.classList.toggle('light', !dark);
  document.querySelector('meta[name="theme-color"]')?.setAttribute('content', dark ? '#1e3a8a' : '#1d4ed8');
}

export function setTheme(next: ThemeChoice) {
  choice = next;
  try {
    if (next === 'auto') localStorage.removeItem(KEY);
    else localStorage.setItem(KEY, next);
  } catch {
    // Private mode: the choice lasts until the page is closed.
  }
  apply();
  listeners.forEach((l) => l());
}

export function useTheme(): ThemeChoice {
  return useSyncExternalStore((l) => {
    listeners.add(l);
    return () => listeners.delete(l);
  }, () => choice);
}

if (typeof document !== 'undefined') {
  apply();
  media?.addEventListener?.('change', apply);
}
