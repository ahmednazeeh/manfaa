'use client';

import { useCallback } from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { cn } from '@/lib/utils';
import {
  AccordionMenu,
  AccordionMenuClassNames,
  AccordionMenuGroup,
  AccordionMenuItem,
  AccordionMenuLabel,
} from '@/components/ui/accordion-menu';
import { ScrollArea } from '@/components/ui/scroll-area';
import { useLayout } from './context';
import { useMarketplaceEnrolment } from '@/lib/queries';
import { APP_MENU, menuItemAllowed } from './menu';

const classNames: AccordionMenuClassNames = {
  root: 'lg:ps-1 space-y-3',
  group: 'gap-px',
  label: 'uppercase text-xs font-medium text-muted-foreground/70 pt-2.25 pb-px',
  item: 'h-8 hover:bg-transparent text-accent-foreground hover:text-primary data-[selected=true]:text-primary data-[selected=true]:bg-muted data-[selected=true]:font-medium',
};

export function SidebarMenu({ className }: { className?: string }) {
  const pathname = usePathname();
  const { me } = useLayout();
  const enrolment = useMarketplaceEnrolment();

  const matchPath = useCallback(
    (path: string): boolean =>
      path === pathname || (path.length > 1 && pathname.startsWith(path)),
    [pathname],
  );

  // An entry appears only for an account holding the permission that opens
  // it, and a section disappears once nothing is left under it — a heading
  // with no rows underneath advertises a screen the reader cannot reach.
  // Navigation cosmetics only: the API's permission gate (403
  // `permission_required`) is the actual enforcement.
  // A store sees the marketplace group only once it is actually selling on
  // it — the platform switch is on AND this store's application was
  // approved. Neither implies the other.
  const sellsOnMarketplace = enrolment.data?.data.state === 'active';

  // Whether the PLATFORM has a marketplace at all, which is a different
  // question from whether this store sells on it. The endpoint 404s behind
  // the kill switch, so an answer of any kind means the switch is on.
  const platformHasMarketplace = enrolment.data !== undefined;

  const sections = APP_MENU.map((section) => {
    if (!section.marketplaceOnly) {
      return section;
    }

    if (!platformHasMarketplace) {
      // No marketplace on this platform: nothing here is real.
      return { ...section, items: [] };
    }

    return {
      ...section,
      // Before approval the group still appears, holding only the way in.
      // The operational screens stay hidden — a store with no listings has
      // no orders to work — but the application form must be reachable, or
      // the door only opens from the inside.
      items: sellsOnMarketplace
        ? section.items
        : section.items.filter((item) => item.beforeEnrolment),
    };
  })
    .map((section) => ({
      ...section,
      items: section.items.filter((item) => !item.hidden && menuItemAllowed(me, item)),
    }))
    .filter((section) => section.items.length > 0);

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
                <Link href={item.path} className="flex items-center grow gap-2">
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
