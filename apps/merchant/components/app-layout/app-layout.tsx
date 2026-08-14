'use client';

import { ReactNode, useEffect } from 'react';
import { MerchantMe } from '@/lib/api';
import { useIsMobile } from '@/hooks/use-mobile';
import { LayoutProvider, useLayout } from './context';
import { Footer } from './footer';
import { Header } from './header';
import { Sidebar } from './sidebar';

/**
 * The merchant panel chrome: the template's demo1 shell (fixed sidebar +
 * fixed header) with the panel's own menu. Uses the demo1 CSS variables
 * shipped in styles/demos/demo1.css, so RTL and dark mode both work.
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

        <main className="grow pt-5" role="content">
          {children}
        </main>

        <Footer />
      </div>
    </>
  );
}

export function AppLayout({
  me,
  children,
}: {
  me: MerchantMe;
  children: ReactNode;
}) {
  return (
    <LayoutProvider me={me}>
      <Main>{children}</Main>
    </LayoutProvider>
  );
}
