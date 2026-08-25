'use client';

import { type OutstandingBucket } from '@manfaa/api-client';
import { MoneyText } from '@manfaa/ui';
import { HandCoins } from 'lucide-react';
import { cn } from '@/lib/utils';
import { hasGst } from '@/lib/gst';
import { useOutstanding, useSettlementPreview, useWallet } from '@/lib/queries';
import { can } from '@/lib/roles';
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
import { PosWaiverCard } from '@/components/dashboard/pos-waiver-card';
import { MerchantFeePromotionBanner } from '@/components/fee-promotion/fee-promotion-banner';
import { QuickActionsCard } from '@/components/dashboard/quick-actions-card';
import {
  TOUR_ANCHORS,
  tourAnchor,
  type TourAnchor,
} from '@/components/onboarding/anchors';
import { TourPrompt } from '@/components/onboarding/tour-prompt';
import { PromptDiscountDeadline } from '@/components/settlement/prompt-discount';

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
  anchor,
}: {
  label: string;
  bucket: OutstandingBucket;
  overdue?: boolean;
  /** Set on the Overdue card alone — the guided tour points at it. */
  anchor?: TourAnchor;
}) {
  return (
    <Card
      data-tour={anchor}
      className={cn(overdue && bucket.count > 0 && 'border-destructive/50')}
    >
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
  // Three separate things behind one screen: reading the ageing is
  // `settlements.view` (which is what put this entry in the menu at all),
  // building a batch is `settlements.create`, and the wallet balance is its
  // own permission again. A reader may hold any one without the others.
  const canSettle = can(me, 'settlements.create');
  const canSeeWallet = can(me, 'wallet.view');
  const outstanding = useOutstanding();
  const wallet = useWallet();
  // The settlement preview, purely to say when the PLAN §1 prompt-payment
  // discount runs out. It claims nothing (no batch, no reference reserved),
  // it is the one endpoint that evaluates the discount, and it is skipped
  // entirely when there is nothing outstanding to settle — a merchant with a
  // clear board is not owed a countdown.
  const settleAllPreview = useSettlementPreview(
    { settleAll: true },
    can(me, 'settlements.preview') &&
      (outstanding.data?.total.count ?? 0) > 0,
    // Ambient banner, not a quote: a 30s-old figure is fine here, and the
    // wizard re-quotes at staleTime 0 before anything is paid.
    'display',
  );

  return (
    <div className="container">
      <Toolbar>
        <ToolbarHeading>
          <ToolbarPageTitle>Dashboard</ToolbarPageTitle>
          <ToolbarDescription>
            Outstanding cashback and fees by age — settle within 15 days
          </ToolbarDescription>
        </ToolbarHeading>
        {canSettle && (
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

      <div className="flex flex-col gap-5 pb-7.5">
        {/* What the platform is charging this store today, when a promotion
            is running. Above everything and OUTSIDE the ageing's error
            branch for the same reason the quick actions are: it is news
            about the store's own terms, and it must not disappear with the
            outstanding figures. Renders nothing the rest of the time. */}
        <MerchantFeePromotionBanner />

        {/* The offer of a walkthrough, above the card the walkthrough
            starts at, and only for the first five days. */}
        <TourPrompt />

        {/* First, and OUTSIDE the ageing's error branch: crediting a
            customer is what a till reaches for, and it neither needs the
            outstanding figures nor should disappear with them. */}
        <QuickActionsCard />

        {outstanding.error ? (
          <ErrorBlock error={outstanding.error} />
        ) : (
          <>
            {settleAllPreview.data && (
              <PromptDiscountDeadline
                discount={settleAllPreview.data.discount}
                rows={settleAllPreview.data.transactions}
              />
            )}

            <PosWaiverCard />

            <div
              {...tourAnchor(TOUR_ANCHORS.ageingBuckets)}
              className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5"
            >
              {BUCKETS.map(({ key, label }) =>
                outstanding.data ? (
                  <BucketCard
                    key={key}
                    label={label}
                    bucket={outstanding.data.buckets[key]}
                    overdue={key === 'overdue'}
                    anchor={
                      key === 'overdue' ? TOUR_ANCHORS.overdueBucket : undefined
                    }
                  />
                ) : (
                  <Skeleton key={key} className="h-28 rounded-xl" />
                ),
              )}
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
              <Card {...tourAnchor(TOUR_ANCHORS.totalPayable)}>
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
                        {/* Absent, not zeroed, while GST is switched off —
                            see lib/gst.ts. The fee above is Manfaa's own
                            charge either way, so the two lines still add up
                            to the payable total. */}
                        {hasGst(outstanding.data.total.fee_gst_laari) && (
                          <div className="flex justify-between gap-3">
                            <span className="text-muted-foreground">
                              GST on fee
                            </span>
                            <MoneyText
                              laari={outstanding.data.total.fee_gst_laari}
                              className="text-secondary-foreground"
                            />
                          </div>
                        )}
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

              {/* Hidden rather than left to fail: the card's error branch says
                  the wallet is unavailable, which reads as an outage when the
                  truth is that this account was never given the wallet. */}
              {canSeeWallet && (
                <Card {...tourAnchor(TOUR_ANCHORS.wallet)}>
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
              )}
            </div>
          </>
        )}
      </div>
    </div>
  );
}
