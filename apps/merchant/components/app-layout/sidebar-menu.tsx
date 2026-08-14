'use client';

import { useCallback } from 'react';
import { cn } from '@/lib/utils';
import {
  AccordionMenu,
  AccordionMenuClassNames,
  AccordionMenuGroup,
  AccordionMenuItem,
  AccordionMenuLabel,
} from '@/components/ui/accordion-menu';
import { ScrollArea } from '@/components/ui/scroll-area';
import { usePathname } from 'next/navigation';
import Link from 'next/link';
import { useLayout } from './context';
import { APP_MENU } from './menu';

const classNames: AccordionMenuClassNames = {
  root: 'lg:ps-1 space-y-3',
  group: 'gap-px',
  label: 'uppercase text-xs font-medium text-muted-foreground/70 pt-2.25 pb-px',
  item: 'h-8 hover:bg-transparent text-accent-foreground hover:text-primary data-[selected=true]:text-primary data-[selected=true]:bg-muted data-[selected=true]:font-medium',
};

export function SidebarMenu({ className }: { className?: string }) {
  const pathname = usePathname();
  const { me } = useLayout();

  const matchPath = useCallback(
    (path: string): boolean =>
      path === pathname || (path.length > 1 && pathname.startsWith(path)),
    [pathname],
  );

  // Owner-only sections are hidden from staff. Navigation cosmetics only —
  // the API's owner gate (403 owner_required) is the actual enforcement.
  const sections = APP_MENU.filter(
    (section) => !section.ownerOnly || me.role === 'owner',
  );

  return (
    <ScrollArea
      className={cn('flex grow shrink-0 py-5 px-5 lg:h-[calc(100vh-5.5rem)]', className)}
    >
      <AccordionMenu
        selectedValue={pathname}
        matchPath={matchPath}
        type="single"
        collapsible
        classNames={classNames}
      >
        {sections.map((section, index) => (
          <AccordionMenuGroup key={section.label ?? index}>
            {section.label && (
              <AccordionMenuLabel>{section.label}</AccordionMenuLabel>
            )}
            {section.items.map((item) => (
              <AccordionMenuItem
                key={item.path}
                value={item.path}
                className="text-sm font-medium"
              >
                <Link
                  href={item.path}
                  className="flex items-center grow gap-2"
                >
                  <item.icon data-slot="accordion-menu-icon" />
                  <span data-slot="accordion-menu-title">{item.title}</span>
                </Link>
              </AccordionMenuItem>
            ))}
          </AccordionMenuGroup>
        ))}
      </AccordionMenu>
    </ScrollArea>
  );
}
