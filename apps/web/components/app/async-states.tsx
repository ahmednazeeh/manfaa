'use client';

import { ReactNode } from 'react';
import { ApiError } from '@manfaa/api-client';
import { TriangleAlert } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Alert, AlertIcon, AlertTitle } from '@/components/ui/alert';
import { Skeleton } from '@/components/ui/skeleton';

export function LoadingBlock({ lines = 3 }: { lines?: number }) {
  return (
    <div className="flex flex-col gap-3 p-5">
      {Array.from({ length: lines }, (_, index) => (
        <Skeleton key={index} className="h-8 rounded-md" />
      ))}
    </div>
  );
}

/** Human-readable message out of an ApiError's JSON body. */
export function apiErrorMessage(error: unknown, fallback: string): string {
  if (error instanceof ApiError) {
    const body = error.body as { message?: unknown } | undefined;
    if (body && typeof body.message === 'string' && body.message.length > 0) {
      return body.message;
    }
  }
  return fallback;
}

export function ErrorBlock({
  error,
  fallback,
}: {
  error: unknown;
  fallback?: string;
}) {
  const { t } = useTranslation();
  return (
    <div className="p-5">
      <Alert variant="destructive" appearance="light">
        <AlertIcon>
          <TriangleAlert />
        </AlertIcon>
        <AlertTitle>
          {apiErrorMessage(error, fallback ?? t('common.genericLoadError'))}
        </AlertTitle>
      </Alert>
    </div>
  );
}

export function EmptyBlock({ children }: { children: ReactNode }) {
  return (
    <div className="flex items-center justify-center p-10 text-sm text-muted-foreground text-center">
      {children}
    </div>
  );
}
