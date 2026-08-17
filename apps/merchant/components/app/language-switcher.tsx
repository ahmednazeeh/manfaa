'use client';

import { getDirection } from '@manfaa/ui';
import { Languages } from 'lucide-react';
import { type AppLanguage } from '@/lib/i18n';
import { useLanguage } from '@/providers/i18n-provider';
import { Button } from '@/components/ui/button';

/**
 * Language toggle for the merchant panel (mirrors the customer app's).
 *
 * A one-tap TOGGLE, not a menu (owner call 2026-08-17: two languages
 * don't need a dropdown). Its label is the DESTINATION language's
 * autonym — rendered in its own script and never translated, so it
 * deliberately bypasses t() and carries no aria-label that would hide
 * the destination from screen readers.
 */
const OTHER_LANGUAGE: Record<AppLanguage, AppLanguage> = {
  en: 'dv',
  dv: 'en',
};

const LANGUAGE_NAMES: Record<AppLanguage, string> = {
  en: 'English',
  dv: 'ދިވެހި',
};

export function LanguageSwitcher() {
  const { language, setLanguage } = useLanguage();
  const target = OTHER_LANGUAGE[language] ?? 'en';

  return (
    <Button
      variant="ghost"
      className="h-9 gap-1.5 rounded-full px-3 hover:bg-primary/10 hover:[&_svg]:text-primary"
      onClick={() => setLanguage(target)}
    >
      <Languages className="size-4! text-muted-foreground" />
      <span
        lang={target}
        dir={getDirection(target)}
        className={target === 'dv' ? 'font-thaana' : undefined}
      >
        {LANGUAGE_NAMES[target]}
      </span>
    </Button>
  );
}
