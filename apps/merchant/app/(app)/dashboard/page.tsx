'use client';

import { type OutstandingBucket } from '@manfaa/api-client';
import { MoneyText } from '@manfaa/ui';
import { HandCoins } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useOutstanding, useWallet } from '@/lib/queries';
import { hasRoleAtLeast } from '@/lib/roles';
import { useLayout } from '@/components/app-layout/context';
import { Button } from '@/components/ui/button';
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import Link from 'next/link';
import {
  Toolbar,
  ToolbarActions,
  ToolbarDescription,
  ToolbarHeading,
  ToolbarPageTitle,
} from '@/components/app-layout/toolbar';
import { ErrorBlock } from '@/components/app/async-states';

const BUCKETS: { key: '0_5' | '6_10' | '11_15' | 'overdue'; label: string }[] = [
  { key: '0_5', label: '0–5 days' },
  { key: '6_10', label: '6–10 days' },
  { key: '11_15', label: '11–15 days' },
  { key: 'overdue', label: 'Overdue' },
];

function BucketCard({
  label,
  bucket,
  overdue,
}: {
  label: string;
  bucket: OutstandingBucket;
  overdue?: boolean;
}) {
  return (
    <Card className={cn(overdue && bucket.count > 0 && 'border-destructive/50')}>
      <CardContent className="flex flex-col gap-1.5 p-5">
        <span
          className={cn(
            'text-xs font-medium uppercase',
            overdue && bucket.count > 0
              ? 'text-destructive'
              : 'text-muted-foreground',
          )}
        >
          {label}
        </span>
        <MoneyText
          laari={bucket.payable_laari}
          className={cn(
            'text-xl font-semibold text-mono',
            overdue && bucket.count > 0 && 'text-destructive',
          )}
        />
        <span className="text-xs text-muted-foreground">
          {bucket.count} transaction{bucket.count === 1 ? '' : 's'}
        </span>
      </CardContent>
    </Card>
  );
}

export default function DashboardPage() {
  const { me } = useLayout();
  // Staff read the ageing but never open the settlement builder — creating
  // a batch is manager work (PLAN §1), and the API refuses the POST anyway.
  const canManage = hasRoleAtLeast(me.role, 'manager');
  const outstanding = useOutstanding();
  const wallet = useWallet();

  return (
    <div className="container">
      <Toolbar>
        <ToolbarHeading>
          <ToolbarPageTitle>Dashboard</ToolbarPageTitle>
          <ToolbarDescription>
            Outstanding cashback and fees by age — settle within 15 days
          </ToolbarDescription>
        </ToolbarHeading>
        {canManage && (
          <ToolbarActions>
            <Button asChild disabled={outstanding.data?.total.count === 0}>
              <Link href="/settlements/new">
                <HandCoins />
                Settle now
              </Link>
            </Button>
          </ToolbarActions>
        )}
      </Toolbar>

      {outstanding.error ? (
        <ErrorBlock error={outstanding.error} />
      ) : (
        <div className="flex flex-col gap-5 pb-7.5">
          <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5">
            {BUCKETS.map(({ key, label }) =>
              outstanding.data ? (
                <BucketCard
                  key={key}
                  label={label}
                  bucket={outstanding.data.buckets[key]}
                  overdue={key === 'overdue'}
                />
              ) : (
                <Skeleton key={key} className="h-28 rounded-xl" />
              ),
            )}
          </div>

          <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
            <Card>
              <CardHeader>
                <CardTitle>Total payable</CardTitle>
              </CardHeader>
              <CardContent className="flex flex-col gap-3">
                {outstanding.data ? (
                  <>
                    <MoneyText
                      laari={outstanding.data.total.payable_laari}
                      className="text-2xl font-semibold text-mono"
                    />
                    <div className="flex flex-col gap-1.5 text-sm">
                      <div className="flex justify-between gap-3">
                        <span className="text-muted-foreground">
                          Customer cashback
                        </span>
                        <MoneyText
                          laari={outstanding.data.total.cashback_laari}
                          className="text-secondary-foreground"
                        />
                      </div>
                      <div className="flex justify-between gap-3">
                        <span className="text-muted-foreground">
                          Platform fee
                        </span>
                        <MoneyText
                          laari={outstanding.data.total.fee_laari}
                          className="text-secondary-foreground"
                        />
                      </div>
                      <div className="flex justify-between gap-3">
                        <span className="text-muted-foreground">
                          GST on fee
                        </span>
                        <MoneyText
                          laari={outstanding.data.total.fee_gst_laari}
                          className="text-secondary-foreground"
                        />
                      </div>
                      <div className="flex justify-between gap-3 border-t border-border pt-1.5">
                        <span className="text-muted-foreground">
                          Outstanding transactions
                        </span>
                        <span className="text-secondary-foreground tabular-nums">
                          {outstanding.data.total.count}
                        </span>
                      </div>
                    </div>
                  </>
                ) : (
                  <Skeleton className="h-28 rounded-md" />
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>Wallet</CardTitle>
                <Button asChild variant="outline" size="sm">
                  <Link href="/wallet">View movements</Link>
                </Button>
              </CardHeader>
              <CardContent className="flex flex-col gap-2">
                {wallet.error ? (
                  <span className="text-sm text-muted-foreground">
                    Wallet unavailable right now.
                  </span>
                ) : wallet.data ? (
                  <>
                    <MoneyText
                      laari={wallet.data.balance_laari}
                      className="text-2xl font-semibold text-mono"
                    />
                    <span className="text-sm text-muted-foreground">
                      Available to fund settlements instead of a bank
                      transfer.
                    </span>
                  </>
                ) : (
                  <Skeleton className="h-16 rounded-md" />
                )}
              </CardContent>
            </Card>
          </div>
        </div>
      )}
    </div>
  );
}
