'use client';

import { createContext, ReactNode, useContext, useState } from 'react';
import { type Customer } from '@manfaa/api-client';

interface LayoutState {
  me: Customer;
  sidebarCollapse: boolean;
  setSidebarCollapse: (open: boolean) => void;
}

const LayoutContext = createContext<LayoutState | undefined>(undefined);

export function LayoutProvider({
  me,
  children,
}: {
  me: Customer;
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
