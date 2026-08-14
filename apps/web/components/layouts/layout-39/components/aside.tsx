import { Sparkles, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useLayout } from "./context";
import { cn } from "@/lib/utils";
import { ScrollArea } from "@/components/ui/scroll-area";

interface AsideProps {
  onClose?: () => void;
}

export function Aside({ onClose }: AsideProps) {
  const { isMobile, isAsideOpen, asideToggle } = useLayout();

  const handleClose = () => {
    if (isMobile && onClose) {
      onClose();
    } else {
      asideToggle();
    }
  };

  return (
    <div className={cn(
      'lg:fixed lg:z-10 lg:top-2.5 lg:bottom-2.5 lg:end-2.5 flex flex-col p-5 items-stretch shrink-0 w-full lg:bg-background lg:border lg:border-input lg:rounded-xl lg:shadow-xs transition-[width,opacity,transform] duration-300 overflow-hidden',
      !isMobile && !isAsideOpen && 'lg:opacity-0 lg:translate-x-4 lg:pointer-events-none lg:shadow-none lg:border-transparent'
    )}
    style={!isMobile ? { width: isAsideOpen ? 'var(--aside-width)' : '0px' } : undefined}
    >
      <div className="flex items-center justify-between pb-5">
        <h1 className="flex items-center gap-2 text-base font-medium">
          <Sparkles className="size-4 text-purple-800" />AI Assistant
        </h1>
        <Button size="sm" variant="dim" className="-me-1.5" onClick={handleClose}><X className="size-4" /></Button>
      </div>
      <ScrollArea className="grow h-[calc(100vh-6rem)]">
        <div className="rounded-lg grow h-screen border border-dashed bg-muted/30"></div>
      </ScrollArea>
    </div>
  );
}
