'use client';

import { getDirection } from '@manfaa/ui';
import { Check, Languages, Moon, Sun } from 'lucide-react';
import { useTheme } from 'next-themes';
import { useTranslation } from 'react-i18next';
import { SUPPORTED_LANGUAGES, type AppLanguage } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { useLanguage } from '@/providers/i18n-provider';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

/**
 * Header controls shared by the authenticated app chrome and the public
 * landing page: language switcher and light/dark toggle.
 *
 * Language names are autonyms — each rendered in its own script and never
 * translated — so they deliberately bypass t().
 */
const LANGUAGE_NAMES: Record<AppLanguage, string> = {
  en: 'English',
  dv: 'ދިވެހި',
};

export function LanguageSwitcher() {
  const { language, setLanguage } = useLanguage();
  const { t } = useTranslation();

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          variant="ghost"
          mode="icon"
          shape="circle"
          aria-label={t('common.language')}
          className="size-9 hover:bg-primary/10 hover:[&_svg]:text-primary"
        >
          <Languages className="size-4.5!" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-40">
        {SUPPORTED_LANGUAGES.map((lang) => (
          <DropdownMenuItem
            key={lang}
            onClick={() => setLanguage(lang)}
            className={cn(
              'justify-between',
              lang === language && 'bg-muted font-medium',
            )}
          >
            <span
              lang={lang}
              dir={getDirection(lang)}
              className={lang === 'dv' ? 'font-thaana' : undefined}
            >
              {LANGUAGE_NAMES[lang]}
            </span>
            {lang === language && <Check className="size-3.5" />}
          </DropdownMenuItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

export function ThemeToggle() {
  const { resolvedTheme, setTheme } = useTheme();
  const { t } = useTranslation();

  return (
    <Button
      variant="ghost"
      mode="icon"
      shape="circle"
      aria-label={t('common.toggleDarkMode')}
      className="size-9 hover:bg-primary/10 hover:[&_svg]:text-primary"
      onClick={() => setTheme(resolvedTheme === 'dark' ? 'light' : 'dark')}
    >
      <Sun className="size-4.5! dark:hidden" />
      <Moon className="size-4.5! hidden dark:block" />
    </Button>
  );
}
