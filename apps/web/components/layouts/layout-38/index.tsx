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

export function Layout38({ children }: { children: React.ReactNode }) {
  return (
    <LayoutProvider
      bodyClassName="bg-muted"
      style={{
        '--sidebar-width': '255px',
        '--sidebar-header-height': '60px',
        '--header-height': '60px',
        '--header-height-mobile': '60px',
      } as React.CSSProperties}
    >
      <Wrapper>
        {children}
      </Wrapper>
    </LayoutProvider>
  );
}
