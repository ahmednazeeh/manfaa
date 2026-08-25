'use client';

import { ReactNode, useState } from 'react';
import { ApiError } from '@manfaa/api-client';
import {
  MutationCache,
  QueryClient,
  QueryClientProvider,
} from '@tanstack/react-query';
import { ADMIN_ATTENTION_QUERY_KEY } from '@/lib/dashboard';

/**
 * One QueryClient per browser session. 4xx responses are terminal (auth,
 * validation, state conflicts) — retrying them only delays the error UI.
 *
 * EVERY SUCCESSFUL MUTATION REFRESHES THE ATTENTION COUNTS, from here rather
 * than from the dozen dialogs that clear a queue.
 *
 * The nav badges and the dashboard's attention tiles read one shared key.
 * Before that, each badge rode along on the LIST query its own screen
 * already invalidated, so approving a store review updated the badge for
 * free. Reproducing that by hand would mean remembering one line in eight
 * dialogs — and in the ninth queue somebody adds next year — which is
 * bookkeeping that is correct on the day it is written and wrong a quarter
 * later. Doing it once, here, cannot fall out of date.
 *
 * The cost is one counts-only request, fired only after something actually
 * changed on the server. That is cheaper than the four paginated list polls
 * this arrangement replaced.
 */
export function QueryProvider({ children }: { children: ReactNode }) {
  const [client] = useState(() => {
    // The cache has to exist before the client that owns it, and its handler
    // needs that client — so the handler reads it back out of this box, which
    // is filled a few lines later and long before any mutation can settle.
    const box: { client?: QueryClient } = {};

    const mutationCache = new MutationCache({
      onSuccess: () => {
        void box.client?.invalidateQueries({
          queryKey: ADMIN_ATTENTION_QUERY_KEY,
        });
      },
    });

    box.client = new QueryClient({
      mutationCache,
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
          refetchOnWindowFocus: false,
          staleTime: 15_000,
        },
      },
    });

    return box.client;
  });

  return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}
