'use client';

import { useState } from 'react';
import {
  listAdminMerchantNotices,
  type MerchantNoticeType,
  type MerchantStanding,
} from '@manfaa/api-client';
import { useQuery } from '@tanstack/react-query';
import { BellRing, TriangleAlert } from 'lucide-react';
import { apiErrorMessage } from '@/lib/api-error';
import { formatDateTime } from '@/lib/format';
import { Alert, AlertDescription, AlertIcon } from '@/components/ui/alert';
import { Badge, type BadgeProps } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ScrollArea } from '@/components/ui/scroll-area';
import {
  Sheet,
  SheetBody,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';

const NOTICE_TYPES: Record<
  MerchantNoticeType,
  { label: string; variant: BadgeProps['variant'] }
> = {
  reminder_day10: { label: 'Day 10 reminder', variant: 'info' },
  urgent_day13: { label: 'Day 13 urgent', variant: 'warning' },
  due_day15: { label: 'Day 15 due', variant: 'warning' },
  suspended: { label: 'Suspended', variant: 'destructive' },
  reinstated: { label: 'Reinstated', variant: 'success' },
  write_off: { label: 'Write-off', variant: 'destructive' },
};

/**
 * The §7 escalation ladder as evidence: every clock step writes a recorded
 * notice, listed here newest first so non-payment can be evidenced later.
 */
export function NoticesSheet({ merchant }: { merchant: MerchantStanding }) {
  const [open, setOpen] = useState(false);

  const query = useQuery({
    queryKey: ['admin', 'merchant-notices', merchant.id],
    queryFn: ({ signal }) => listAdminMerchantNotices(merchant.id, { signal }),
    enabled: open,
  });

  return (
    <Sheet open={open} onOpenChange={setOpen}>
      <SheetTrigger asChild>
        <Button variant="outline" size="sm">
          <BellRing />
          Notices
        </Button>
      </SheetTrigger>
      <SheetContent side="right" className="sm:max-w-md">
        <SheetHeader>
          <SheetTitle>{merchant.name}</SheetTitle>
          <SheetDescription>
            Recorded clock notices, newest first — the evidence trail behind
            reminders, suspension and write-off.
          </SheetDescription>
        </SheetHeader>
        <SheetBody className="grow p-0">
          <ScrollArea className="h-full px-5 py-4">
            {query.isPending ? (
              <div className="flex flex-col gap-3">
                <Skeleton className="h-16 w-full" />
                <Skeleton className="h-16 w-full" />
                <Skeleton className="h-16 w-full" />
              </div>
            ) : query.isError ? (
              <Alert variant="destructive" appearance="light" size="sm">
                <AlertIcon>
                  <TriangleAlert />
                </AlertIcon>
                <AlertDescription>
                  {apiErrorMessage(query.error)}
                </AlertDescription>
              </Alert>
            ) : query.data.data.length === 0 ? (
              <p className="py-8 text-center text-sm text-muted-foreground">
                No notices recorded for this merchant.
              </p>
            ) : (
              <ol className="flex flex-col gap-3">
                {query.data.data.map((notice) => {
                  const type = NOTICE_TYPES[notice.type];
                  return (
                    <li
                      key={notice.id}
                      className="rounded-lg border border-border p-3"
                    >
                      <div className="flex items-center justify-between gap-2">
                        <Badge
                          variant={type.variant}
                          appearance="light"
                          size="sm"
                        >
                          {type.label}
                        </Badge>
                        <span className="text-xs text-muted-foreground">
                          {formatDateTime(notice.sent_at)}
                        </span>
                      </div>
                      <div className="mt-2 text-xs text-muted-foreground">
                        Channel:{' '}
                        <span className="capitalize">{notice.channel}</span>
                      </div>
                      {notice.payload &&
                      Object.keys(notice.payload).length > 0 ? (
                        <pre className="mt-2 overflow-x-auto rounded-md bg-muted p-2 text-xs">
                          {JSON.stringify(notice.payload, null, 2)}
                        </pre>
                      ) : null}
                    </li>
                  );
                })}
              </ol>
            )}
          </ScrollArea>
        </SheetBody>
      </SheetContent>
    </Sheet>
  );
}
