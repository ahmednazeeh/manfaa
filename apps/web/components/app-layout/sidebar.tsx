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
      {/* The coral M mark + the app's own wordmark (the template SVGs spell
          "METRONIC"). The mark always shows; the `default-logo` class hides
          the word while the sidebar is collapsed (demo1.css). */}
      <Link
        href="/dashboard"
        className="flex items-center gap-2"
        aria-label={t('common.appName')}
      >
        <img
          src={toAbsoluteUrl('/media/app/mini-logo.svg?v=mf2')}
          className="h-[22px] max-w-none shrink-0"
          alt=""
        />
        <span className="default-logo text-lg font-semibold tracking-tight text-mono">
          {t('common.appName')}
        </span>
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
