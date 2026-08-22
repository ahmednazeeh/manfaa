'use client';

import { BrandMark } from '@manfaa/ui';
import { useTranslation } from 'react-i18next';

export function ScreenLoader() {
  const { t } = useTranslation();

  return (
    <div className="flex flex-col items-center gap-2 justify-center fixed inset-0 z-50 transition-opacity duration-700 ease-in-out">
      <BrandMark
        shape="square"
        className="h-10 w-auto object-contain"
        alt={t('common.appName')}
      />
      <div className="text-muted-foreground font-medium text-sm">
        {t('common.loading')}
      </div>
    </div>
  );
}
