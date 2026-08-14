import { Metadata } from 'next';
import { Wrapper } from './components/wrapper';
import { LayoutProvider } from './components/context';

// Generate metadata for the layout
export async function generateMetadata(): Promise<Metadata> {
  // You can access route params here if needed
  // const { params } = props;
  
  return {
    title: 'Dashboard',
    description: '',
  };
}

export function Layout39({ children }: { children: React.ReactNode }) {
  return (
    <LayoutProvider
      bodyClassName="bg-zinc-100 dark:bg-zinc-900 lg:overflow-hidden"
      style={{
        '--sidebar-width': '250px',
        '--sidebar-width-mobile': '225px',
        '--sidebar-width-collapsed': '60px',
        '--aside-width': '320px',
        '--aside-width-mobile': '300px',
        '--header-height': '60px',
        '--header-height-mobile': '70px',
      } as React.CSSProperties}
    >
      <Wrapper>
        {children}
      </Wrapper>
    </LayoutProvider>
  );
}
