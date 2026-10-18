import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import deCommon from './locales/de/common.json';
import enCommon from './locales/en/common.json';

/**
 * All UI texts come from src/locales/<lang>/*.json. Adding a language means
 * adding a folder with the same files and listing it here.
 */
export const resources = {
  de: { common: deCommon },
  en: { common: enCommon },
} as const;

export type Language = keyof typeof resources;
export const LANGUAGES = Object.keys(resources) as Language[];
export const DEFAULT_LANGUAGE: Language = 'de';

const STORAGE_KEY = 'vp.language';

function initialLanguage(): Language {
  try {
    const stored = localStorage.getItem(STORAGE_KEY);
    if (stored && stored in resources) return stored as Language;
  } catch {
    // Storage may be unavailable (private mode); fall back to the default.
  }
  return DEFAULT_LANGUAGE;
}

export function changeLanguage(language: Language): void {
  void i18n.changeLanguage(language);
  document.documentElement.lang = language;
  try {
    localStorage.setItem(STORAGE_KEY, language);
  } catch {
    // Not persisted – the choice still applies for this visit.
  }
}

void i18n.use(initReactI18next).init({
  resources,
  lng: initialLanguage(),
  fallbackLng: DEFAULT_LANGUAGE,
  defaultNS: 'common',
  interpolation: { escapeValue: false },
});

export default i18n;
