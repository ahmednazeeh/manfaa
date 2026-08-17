'use client';

import { ReactNode, useEffect } from 'react';
import { type Customer } from '@manfaa/api-client';
import { useIsMobile } from '@/hooks/use-mobile';
import { BottomNav } from './bottom-nav';
import { LayoutProvider, useLayout } from './context';
import { Header } from './header';
import { Sidebar } from './sidebar';

/**
 * The customer app chrome: the template's demo1 shell (fixed sidebar +
 * fixed header) with the customer's own menu. Uses the demo1 CSS variables
 * shipped in styles/demos/demo1.css, so RTL (Dhivehi) and dark mode both
 * work without touching this file.
 */
function Main({ children }: { children: ReactNode }) {
  const isMobile = useIsMobile();
  const { sidebarCollapse } = useLayout();

  useEffect(() => {
    const bodyClass = document.body.classList;

    if (sidebarCollapse) {
      bodyClass.add('sidebar-collapse');
    } else {
      bodyClass.remove('sidebar-collapse');
    }
  }, [sidebarCollapse]);

  useEffect(() => {
    const bodyClass = document.body.classList;

    bodyClass.add('demo1');
    bodyClass.add('sidebar-fixed');
    bodyClass.add('header-fixed');

    const timer = setTimeout(() => {
      bodyClass.add('layout-initialized');
    }, 1000);

    return () => {
      bodyClass.remove('demo1');
      bodyClass.remove('sidebar-fixed');
      bodyClass.remove('sidebar-collapse');
      bodyClass.remove('header-fixed');
      bodyClass.remove('layout-initialized');
      clearTimeout(timer);
    };
  }, []);

  return (
    <>
      {!isMobile && <Sidebar />}

      <div className="wrapper flex grow flex-col">
        <Header />

        {/* No footer on authenticated screens — the wallet is a tool, not a
            page that needs a colophon. The bottom padding below lg clears
            the fixed BottomNav (h ≈ 3.5rem) plus the device safe area. */}
        <main
          className="grow pt-5 pb-[calc(4.5rem+env(safe-area-inset-bottom))] lg:pb-5"
          role="content"
        >
          {children}
        </main>
      </div>

      <BottomNav />
    </>
  );
}

export function AppLayout({
  me,
  children,
}: {
  me: Customer;
  children: ReactNode;
}) {
  return (
    <LayoutProvider me={me}>
      <Main>{children}</Main>
    </LayoutProvider>
  );
}
