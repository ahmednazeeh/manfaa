'use client';

import { MerchantMe } from '@/lib/api';
import { createContext, ReactNode, useContext, useState } from 'react';

interface LayoutState {
  me: MerchantMe;
  sidebarCollapse: boolean;
  setSidebarCollapse: (open: boolean) => void;
}

const LayoutContext = createContext<LayoutState | undefined>(undefined);

export function LayoutProvider({
  me,
  children,
}: {
  me: MerchantMe;
  children: ReactNode;
}) {
  const [sidebarCollapse, setSidebarCollapse] = useState(false);

  return (
    <LayoutContext.Provider value={{ me, sidebarCollapse, setSidebarCollapse }}>
      {children}
    </LayoutContext.Provider>
  );
}

export const useLayout = () => {
  const context = useContext(LayoutContext);
  if (!context) {
    throw new Error('useLayout must be used within a LayoutProvider');
  }
  return context;
};
