import { PanelLeft, PanelRight } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useLayout } from './context';
import { toAbsoluteUrl } from '@/lib/helpers';
import Link from 'next/link';

export function SidebarHeader() {
  const { sidebarToggle, isSidebarOpen } = useLayout();

  if (!isSidebarOpen) {
    return (
      <div className="flex items-center justify-center shrink-0 px-2.5 py-3.5">
        <Button mode="icon" variant="ghost" onClick={() => sidebarToggle()} className="hidden lg:inline-flex shrink-0">
          <PanelRight />
        </Button>
      </div>
    );
  }

  return (
    <div className="flex items-center justify-between shrink-0 px-3 py-3.5">
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
        <span className="text-mono text-sm font-medium">KeenAI</span>
      </Link>

      <Button mode="icon" variant="ghost" onClick={() => sidebarToggle()} className="hidden lg:inline-flex shrink-0">
        <PanelLeft />
      </Button>
    </div>
  );
}
