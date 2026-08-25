'use client';

import Link from 'next/link';
import { BrandMark } from '@manfaa/ui';
import { ChevronFirst } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { OnboardingTasklist } from '@/components/onboarding/onboarding-tasklist';
import { useLayout } from './context';
import { SidebarMenu } from './sidebar-menu';

function SidebarHeader() {
  const { sidebarCollapse, setSidebarCollapse } = useLayout();

  return (
    <div className="sidebar-header hidden lg:flex items-center relative justify-between px-3 lg:px-6 shrink-0">
      {/* The collapse classes go on the WRAPPERS, never on the images.
          demo1.css hides `.small-logo` while the sidebar is expanded, but
          BrandMark's own `dark:block` on the same element beat it in the
          cascade and both marks drew at once. Different elements, no fight:
          the span decides collapsed-vs-expanded, the images inside decide
          light-vs-dark. */}
      <Link href="/dashboard" aria-label="Manfaa" className="flex items-center">
        <span className="default-logo">
          <BrandMark className="h-9 w-auto max-w-[190px] object-contain" />
        </span>
        <span className="small-logo">
          <BrandMark shape="square" className="h-8 w-auto object-contain" />
        </span>
      </Link>
      <Button
        onClick={() => setSidebarCollapse(!sidebarCollapse)}
        size="sm"
        mode="icon"
        variant="outline"
        aria-label="Toggle sidebar"
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
      {/* THE NAV IS THE PRIMARY THING; THE TASKLIST IS THE GUEST.
          A column of two: the menu takes what is left (`grow min-h-0`, so it
          shrinks and scrolls inside its own ScrollArea) and the guided-setup
          list sits under it at its natural height, capped at 40vh. That
          ordering is what stops a five-row checklist from pushing Wallet and
          Settings off the bottom of a 13-inch laptop — before this the menu
          had a fixed `h-[calc(100vh-5.5rem)]` and anything added below it
          simply went past the edge of the screen. */}
      <div className="overflow-hidden flex flex-col grow min-h-0">
        <div className="w-(--sidebar-default-width) flex flex-col grow min-h-0">
          {/* basis-0 + grow, not h-auto alone: a flex item sized entirely by
              grow has a definite height, which is what the ScrollArea's own
              `h-full` viewport needs in order to scroll instead of running
              past the bottom of the column. */}
          <SidebarMenu className="lg:h-auto basis-0 grow shrink min-h-0" />
          <OnboardingTasklist />
        </div>
      </div>
    </div>
  );
}
