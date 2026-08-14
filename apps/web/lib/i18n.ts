import dv from '@/locales/dv.json';
import en from '@/locales/en.json';
import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';

/**
 * i18n bootstrap (§2 localisation): every user-facing string in the customer
 * app goes through t() against locales/en.json from day one, so the Dhivehi
 * pass is a translation file plus an RTL flip — never a code change.
 *
 * Languages: 'en' and 'dv' (Dhivehi, Thaana script — RTL). The server
 * always renders English; the persisted choice (localStorage, see
 * I18nProvider) is applied after hydration so server and client markup
 * agree. Numbers, money and dates keep Latin digits in BOTH languages —
 * the MVR convention (Thaana has no digits; Maldivian financial material
 * prints Latin numerals) — so formatters in lib/format.ts and @manfaa/ui
 * are locale-independent by design.
 *
 * Initialised synchronously (initAsync: false) with bundled resources so
 * it is safe during server prerendering as well as in the browser.
 */

export const SUPPORTED_LANGUAGES = ['en', 'dv'] as const;

export type AppLanguage = (typeof SUPPORTED_LANGUAGES)[number];

export function isAppLanguage(value: string): value is AppLanguage {
  return (SUPPORTED_LANGUAGES as readonly string[]).includes(value);
}

/** localStorage key persisting the customer's language choice. */
export const LANGUAGE_STORAGE_KEY = 'manfaa-language';

if (!i18n.isInitialized) {
  void i18n.use(initReactI18next).init({
    resources: {
      en: { translation: en },
      dv: { translation: dv },
    },
    lng: 'en',
    fallbackLng: 'en',
    supportedLngs: SUPPORTED_LANGUAGES,
    initAsync: false,
    interpolation: {
      // React already escapes interpolated values.
      escapeValue: false,
    },
  });
}

export default i18n;
