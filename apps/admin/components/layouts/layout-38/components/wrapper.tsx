import { Sidebar } from './sidebar';
import { Header } from './header';
import { useLayout } from './context';
import { useEffect, useState } from 'react';
import { cn } from '@/lib/utils';

export function Wrapper({ children }: { children: React.ReactNode }) {
  const { isMobile } = useLayout();
  const [enableTransitions, setEnableTransitions] = useState(false);

  useEffect(() => {
    const id = requestAnimationFrame(() => setEnableTransitions(true));
    return () => cancelAnimationFrame(id);
  }, []);


  return (
    <div className="flex h-screen w-full [&_.container-fluid]:px-5">
      {!isMobile && <Sidebar />}
      
      <div className="flex flex-col flex-1 min-w-0 w-full pt-(--header-height-mobile) lg:pt-0">
        {isMobile && <Header />}
        <div className="flex grow lg:mx-2.5 mx-5 py-2.5">
          <div className={cn(
              'grow bg-background overflow-y-auto duration-300 lg:ms-[calc(var(--sidebar-width-collapsed)+0.6rem)] lg:in-data-[sidebar-open=true]:ms-[calc(var(--sidebar-width)+0.6rem)] border border-input rounded-xl shadow-xs', 
              enableTransitions ? 'transition-all duration-300' : 'transition-none'
            )}>
            <main
              className="grow"
              role="content"
            >
              {children}
            </main>
          </div>
        </div>
      </div>
    </div>
  );
}
