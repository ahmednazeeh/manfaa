'use client';

import { ReactNode } from 'react';
import { TriangleAlert } from 'lucide-react';
import { apiErrorMessage } from '@/lib/queries';
import {
  Alert,
  AlertIcon,
  AlertTitle,
} from '@/components/ui/alert';
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

export function ErrorBlock({
  error,
  fallback = 'Something went wrong loading this data.',
}: {
  error: unknown;
  fallback?: string;
}) {
  return (
    <div className="p-5">
      <Alert variant="destructive" appearance="light">
        <AlertIcon>
          <TriangleAlert />
        </AlertIcon>
        <AlertTitle>{apiErrorMessage(error, fallback)}</AlertTitle>
      </Alert>
    </div>
  );
}

export function EmptyBlock({ children }: { children: ReactNode }) {
  return (
    <div className="flex items-center justify-center p-10 text-sm text-muted-foreground">
      {children}
    </div>
  );
}
