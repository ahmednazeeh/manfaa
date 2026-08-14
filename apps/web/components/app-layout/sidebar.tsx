'use client';

import Link from 'next/link';
import { ChevronFirst } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { toAbsoluteUrl } from '@/lib/helpers';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { useLayout } from './context';
import { SidebarMenu } from './sidebar-menu';

function SidebarHeader() {
  const { sidebarCollapse, setSidebarCollapse } = useLayout();
  const { t } = useTranslation();

  return (
    <div className="sidebar-header hidden lg:flex items-center relative justify-between px-3 lg:px-6 shrink-0">
      <Link href="/dashboard">
        <div className="dark:hidden">
          <img
            src={toAbsoluteUrl('/media/app/default-logo.svg')}
            className="default-logo h-[22px] max-w-none"
            alt={t('common.appName')}
          />
          <img
            src={toAbsoluteUrl('/media/app/mini-logo.svg')}
            className="small-logo h-[22px] max-w-none"
            alt={t('common.appName')}
          />
        </div>
        <div className="hidden dark:block">
          <img
            src={toAbsoluteUrl('/media/app/default-logo-dark.svg')}
            className="default-logo h-[22px] max-w-none"
            alt={t('common.appName')}
          />
          <img
            src={toAbsoluteUrl('/media/app/mini-logo.svg')}
            className="small-logo h-[22px] max-w-none"
            alt={t('common.appName')}
          />
        </div>
      </Link>
      <Button
        onClick={() => setSidebarCollapse(!sidebarCollapse)}
        size="sm"
        mode="icon"
        variant="outline"
        aria-label={t('common.toggleSidebar')}
        className={cn(
          'size-7 absolute start-full top-2/4 rtl:translate-x-2/4 -translate-x-2/4 -translate-y-2/4',
          sidebarCollapse ? 'ltr:rotate-180' : 'rtl:rotate-180',
        )}
      >
        <ChevronFirst className="size-4!" />
      </Button>
    </div>
  );
}

export function Sidebar() {
  return (
    <div className="sidebar bg-background lg:border-e lg:border-border lg:fixed lg:top-0 lg:bottom-0 lg:z-20 lg:flex flex-col items-stretch shrink-0">
      <SidebarHeader />
      <div className="overflow-hidden">
        <div className="w-(--sidebar-default-width)">
          <SidebarMenu />
        </div>
      </div>
    </div>
  );
}
