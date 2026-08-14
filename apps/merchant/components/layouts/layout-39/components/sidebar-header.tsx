import { useLayout } from './context';
import { toAbsoluteUrl } from '@/lib/helpers';
import { Button } from '@/components/ui/button';
import { PanelLeft, PanelRight } from 'lucide-react';
import Link from 'next/link';

export function SidebarHeader() {
  const { sidebarToggle, isSidebarOpen } = useLayout();

  if (!isSidebarOpen) {
    return (
      <div className="flex items-center justify-center shrink-0 px-3 py-3.5">
        <Button mode="icon" variant="ghost" onClick={() => sidebarToggle()} className="hidden lg:inline-flex shrink-0">
          <PanelRight />
        </Button>
      </div>
    );
  }

  return (
    <div className="flex items-center justify-between shrink-0 px-3 py-3.5">
      {/* Brand */}
      <Link href="/layout-39" className="flex items-center gap-2">
        <div
          className="
            flex items-center p-[5.5px] gap-2
            rounded-[60px] border border-[rgba(255,255,255,0.3)]
            bg-linear-to-r from-primary to-blue-600
            dark:from-blue-950 dark:to-blue-800
            hover:opacity-90 dark:hover:opacity-85
            shadow-[0_0_0_1px_#000]
            transition-opacity
          "
        >
          <img src={toAbsoluteUrl('/media/app/logo-35.svg')} alt="image" className="min-w-[16px]" />
        </div>
        <span className="text-mono text-sm font-medium">KeenTodo</span>
      </Link>

      <Button mode="icon" variant="ghost" onClick={() => sidebarToggle()} className="hidden lg:inline-flex shrink-0">
        <PanelLeft />
      </Button>
    </div>
  );
}
