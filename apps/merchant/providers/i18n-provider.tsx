'use client';

import {
  createContext,
  ReactNode,
  useCallback,
  useContext,
  useEffect,
  useState,
} from 'react';
import { useDirection } from '@manfaa/ui';
import { I18nextProvider } from 'react-i18next';
import i18n, {
  isAppLanguage,
  LANGUAGE_STORAGE_KEY,
  type AppLanguage,
} from '@/lib/i18n';

interface LanguageContextValue {
  language: AppLanguage;
  /** Switches the UI language and persists the choice (localStorage). */
  setLanguage: (language: AppLanguage) => void;
}

const LanguageContext = createContext<LanguageContextValue | null>(null);

/** The active language + setter for the header's language switcher. */
export function useLanguage(): LanguageContextValue {
  const context = useContext(LanguageContext);
  if (context === null) {
    throw new Error('useLanguage must be used within I18nProvider');
  }
  return context;
}

/**
 * Makes the app's i18next instance available to every useTranslation(), and
 * owns the language choice: default English, persisted per browser, applied
 * after hydration (SSR always renders English so markup matches).
 *
 * When Dhivehi is active it also flips the document to RTL + Thaana:
 * useDirection (from @manfaa/ui) keeps <html dir> in sync, and setting
 * <html lang="dv"> activates the Thaana font rule in @manfaa/ui/styles.css.
 */
export function I18nProvider({ children }: { children: ReactNode }) {
  const [language, setLanguageState] = useState<AppLanguage>('en');

  // Restore the persisted choice once, on the client only.
  useEffect(() => {
    const stored = window.localStorage.getItem(LANGUAGE_STORAGE_KEY);
    if (stored !== null && isAppLanguage(stored)) {
      setLanguageState(stored);
    }
  }, []);

  // dv -> dir="rtl" on <html>; logical CSS properties mirror the layout.
  useDirection(language);

  useEffect(() => {
    if (i18n.language !== language) {
      void i18n.changeLanguage(language);
    }
    document.documentElement.lang = language;
  }, [language]);

  const setLanguage = useCallback((next: AppLanguage) => {
    window.localStorage.setItem(LANGUAGE_STORAGE_KEY, next);
    setLanguageState(next);
  }, []);

  return (
    <LanguageContext.Provider value={{ language, setLanguage }}>
      <I18nextProvider i18n={i18n}>{children}</I18nextProvider>
    </LanguageContext.Provider>
  );
}
