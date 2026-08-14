import {
  createContext,
  ReactNode,
  useContext,
  useEffect,
  useMemo,
  useState,
} from 'react';
import { useIsMobile } from '@/hooks/use-mobile';
import { TooltipProvider } from '@/components/ui/tooltip';

// Define the shape of the layout state
interface LayoutState {
  style: React.CSSProperties;
  bodyClassName: string;
  isMobile: boolean;
  isSidebarOpen: boolean;
  isAsideOpen: boolean;
  asideToggle: () => void;
  sidebarToggle: () => void;
}

// Create the context
const LayoutContext = createContext<LayoutState | undefined>(undefined);

// Provider component
interface LayoutProviderProps {
  children: ReactNode;
  style?: React.CSSProperties;
  bodyClassName?: string;
}

export function LayoutProvider({ children, style: customStyle, bodyClassName = '' }: LayoutProviderProps) {
  const isMobile = useIsMobile();
  const [isSidebarOpen, setIsSidebarOpen] = useState(true);
  const [isAsideOpen, setIsAsideOpen] = useState(true);

  const defaultStyle = useMemo(() => ({
    '--sidebar-width': '250px',
    '--sidebar-width-mobile': '225px',
    '--sidebar-width-collapsed': '60px',
    '--aside-width': '320px',
    '--aside-width-mobile': '300px',
    '--header-height': '60px',
    '--header-height-mobile': '70px',
  }), []);

  const style: React.CSSProperties = useMemo(() => ({
    ...defaultStyle,
    ...customStyle,
  }), [defaultStyle, customStyle]);

  // Sidebar toggle function
  const sidebarToggle = () => setIsSidebarOpen((open) => !open);
  
  // Aside expanded toggle function
  const asideToggle = () => setIsAsideOpen((open) => !open);

  // Apply CSS variables to HTML root and body className
  useEffect(() => {
    const html = document.documentElement;
    const body = document.body;
    
    // Store original values for cleanup
    const originalHtmlStyle = html.style.cssText;
    const originalBodyClasses = body.className;

    // Apply CSS variables to HTML root element from the merged style
    Object.entries(style).forEach(([property, value]) => {
      if (typeof value === 'string' && property.startsWith('--')) {
        html.style.setProperty(property, value);
      }
    });

    // Apply body className if provided
    if (bodyClassName) {
      body.className = `${originalBodyClasses} ${bodyClassName}`.trim();
    }

    // Add data attributes to body for layout states
    body.setAttribute('data-sidebar-open', isSidebarOpen.toString());
    body.setAttribute('data-aside-open', isAsideOpen.toString());

    // Cleanup function
    return () => {
      html.style.cssText = originalHtmlStyle;
      body.className = originalBodyClasses;
      body.removeAttribute('data-sidebar-open');
      body.removeAttribute('data-aside-open');
    };
  }, [style, bodyClassName, isSidebarOpen, isAsideOpen]);

  return (
    <LayoutContext.Provider
      value={{
        bodyClassName,
        style,
        isMobile,
        isSidebarOpen,
        isAsideOpen,
        asideToggle,
        sidebarToggle
      }}
    >
      <div
        data-slot="layout-wrapper"
        className="flex grow"
      >
        <TooltipProvider delayDuration={0}>
          {children}
        </TooltipProvider>
      </div>
    </LayoutContext.Provider>
  );
}

// Custom hook for consuming the context
export const useLayout = () => {
  const context = useContext(LayoutContext);
  if (!context) {
    throw new Error('useLayout must be used within a LayoutProvider');
  }
  return context;
};
