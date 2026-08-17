'use client';

import { useCallback } from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/utils';
import {
  AccordionMenu,
  AccordionMenuClassNames,
  AccordionMenuGroup,
  AccordionMenuItem,
} from '@/components/ui/accordion-menu';
import { ScrollArea } from '@/components/ui/scroll-area';
import { APP_MENU } from './menu';

const classNames: AccordionMenuClassNames = {
  root: 'lg:ps-1 space-y-3',
  group: 'gap-px',
  label: 'uppercase text-xs font-medium text-muted-foreground/70 pt-2.25 pb-px',
  // Active item: a thin brand pill on the item's start edge (logical
  // inset, so Dhivehi mirrors it to the right edge) plus a slightly
  // stronger label and a full-strength brand icon. The pill sits INSIDE
  // the item box — the ScrollArea viewport clips anything hung outside it.
  // It is always present but transparent, so selection never shifts layout.
  item: cn(
    'h-8 hover:bg-transparent text-accent-foreground hover:text-primary',
    'before:absolute before:start-0 before:top-1/2 before:h-4.5 before:w-[3px]',
    'before:-translate-y-1/2 before:rounded-full before:bg-transparent before:content-[""]',
    'data-[selected=true]:text-primary data-[selected=true]:bg-muted data-[selected=true]:font-semibold',
    'data-[selected=true]:before:bg-brand data-[selected=true]:[&_svg]:opacity-100 data-[selected=true]:[&_svg]:text-brand',
  ),
};

export function SidebarMenu({ className }: { className?: string }) {
  const pathname = usePathname();
  const { t } = useTranslation();

  const matchPath = useCallback(
    (path: string): boolean =>
      path === pathname || (path.length > 1 && pathname.startsWith(path)),
    [pathname],
  );

  return (
    <ScrollArea
      className={cn(
        'flex grow shrink-0 py-5 px-5 lg:h-[calc(100vh-5.5rem)]',
        className,
      )}
    >
      <AccordionMenu
        selectedValue={pathname}
        matchPath={matchPath}
        type="single"
        collapsible
        classNames={classNames}
      >
        <AccordionMenuGroup>
          {APP_MENU.map((item) => (
            <AccordionMenuItem
              key={item.path}
              value={item.path}
              className="text-sm font-medium"
            >
              <Link href={item.path} className="flex items-center grow gap-2">
                <item.icon data-slot="accordion-menu-icon" />
                <span data-slot="accordion-menu-title">{t(item.titleKey)}</span>
              </Link>
            </AccordionMenuItem>
          ))}
        </AccordionMenuGroup>
      </AccordionMenu>
    </ScrollArea>
  );
}
