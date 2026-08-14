import {
  Sheet,
  SheetBody,
  SheetContent,
  SheetHeader,
  SheetTrigger,
} from '@/components/ui/sheet';
import { Button } from '@/components/ui/button';
import { Menu } from 'lucide-react';
import { useEffect, useState } from 'react';
import { SidebarContent } from './sidebar-content';
import { toAbsoluteUrl } from '@/lib/helpers';
import { SidebarFooter } from './sidebar-footer';
import { usePathname } from 'next/navigation';
import Link from 'next/link';

export function Header() {
  const pathname = usePathname();
  const [isSheetOpen, setIsSheetOpen] = useState(false);
  
  // Close sheet when route changes
  useEffect(() => {
    setIsSheetOpen(false);
  }, [pathname]);
  
  return (
    <header className="transition-[start,end] duration-300 fixed top-0 start-0 lg:start-[calc(0.6rem+var(--sidebar-collapsed-width))] lg:in-data-[sidebar-open=true]:start-[calc(var(--sidebar-width)+0.7rem)] end-0 z-50 flex items-center shrink-0 bg-background/95 backdrop-blur-sm supports-backdrop-filter:bg-muted h-(--header-height-mobile) lg:h-[var(--header-height)] pe-[var(--removed-body-scroll-bar-size,0px)]">
      <div className="container-fluid grow flex items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          {/* Brand */}
          <Link href="/layout-38" className="flex items-center gap-2">
            <div
              className="
                flex items-center p-[8px] gap-2
                rounded-[60px]
                bg-gradient-to-r from-primary to-purple-600
                dark:from-purple-950 dark:to-purple-800
                shadow-lg
              "
            >
              <img src={toAbsoluteUrl('/media/app/logo-34.svg')} alt="image" className="min-w-[16px]" />
            </div>
          </Link>

          <Sheet open={isSheetOpen} onOpenChange={setIsSheetOpen}>
            <SheetTrigger asChild>
              <Button variant="ghost" mode="icon" size="sm">
                <Menu className="size-4" />
              </Button>
            </SheetTrigger>
            <SheetContent
              className="p-0 gap-0 w-[255px]"
              side="left"
              close={false}
            >
              <SheetHeader className="p-0 space-y-0" />
              <SheetBody className="flex grow p-0">
                <SidebarContent />
              </SheetBody>
            </SheetContent>
          </Sheet>
        </div >

        <SidebarFooter />
      </div>
    </header>
  );
}
