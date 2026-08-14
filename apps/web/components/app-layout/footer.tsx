'use client';

import { useTranslation } from 'react-i18next';

export function Footer() {
  const { t } = useTranslation();
  const currentYear = new Date().getFullYear();

  return (
    <footer className="footer">
      <div className="container">
        <div className="flex justify-center md:justify-start items-center py-5">
          <span className="flex gap-2 font-normal text-sm text-muted-foreground">
            {t('common.footerLine', { year: currentYear })}
          </span>
        </div>
      </div>
    </footer>
  );
}
