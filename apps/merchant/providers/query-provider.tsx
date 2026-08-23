'use client';

import { ApiError } from '@manfaa/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ReactNode, useState } from 'react';

/** One QueryClient per browser tab; 4xx responses are never retried. */
export function QueryProvider({ children }: { children: ReactNode }) {
  const [client] = useState(
    () =>
      new QueryClient({
        defaultOptions: {
          queries: {
            retry: (failureCount, error) => {
              if (
                error instanceof ApiError &&
                error.status >= 400 &&
                error.status < 500
              ) {
                return false;
              }
              return failureCount < 2;
            },
            staleTime: 30_000,
            // Refetch stale queries when the tab regains focus: a merchant
            // returning from the till app sees money moved elsewhere within
            // one focus, and staleTime gates it so a quick tab-flick costs
            // nothing (owner report 2026-08-23).
            refetchOnWindowFocus: true,
          },
        },
      }),
  );

  return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}
