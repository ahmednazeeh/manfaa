'use client';

import Link from 'next/link';
import {
  DASHBOARD_ATTENTION_QUEUES,
  type DashboardAttention,
  type DashboardAttentionQueue,
} from '@manfaa/api-client';
import {
  BadgeCheck,
  ClipboardCheck,
  FileDiff,
  Landmark,
  PiggyBank,
  ShieldAlert,
  type LucideIcon,
} from 'lucide-react';
import { formatCount } from '@/lib/reports';
import { cn } from '@/lib/utils';

/**
 * WHAT IS WAITING ON A PERSON — the first thing on the page, because it is
 * the only section that asks the reader to do something.
 *
 * A count here is the queue's OWN predicate, counted by the endpoint that
 * serves that queue, so a tile can never say 3 while the screen behind it
 * shows 4. Each tile is a link to the screen that clears it — and to that
 * screen ALREADY FILTERED to the rows it counted, because a number an admin
 * cannot act on from where they are reading it is a number they will learn
 * to skip, and a number that lands them on a different set is worse than no
 * number.
 *
 * It counts what the list lists. `settlements_payment_review` is BATCHES,
 * not receipts, because /settlements lists batches and one batch can carry
 * several pending receipts at once.
 *
 * QUIET AT ZERO, ON PURPOSE. An empty queue wears the ordinary card border
 * and muted ink; only a non-zero count takes the warning treatment. A wall of
 * amber zeros trains the reader to ignore amber, which costs exactly the one
 * moment the panel exists for.
 */

interface QueueMeta {
  label: string;
  href: string;
  icon: LucideIcon;
  /** What clearing this queue actually means. */
  hint: string;
}

const QUEUES: Record<DashboardAttentionQueue, QueueMeta> = {
  settlements_payment_review: {
    label: 'Settlement receipts',
    // The FILTERED list, never the default tab: the promise this section
    // makes is that the tile's number is the number on the screen it opens,
    // and /settlements without a state lands on every batch ever raised.
    href: '/settlements?state=payment_review',
    icon: Landmark,
    hint: 'Batches whose receipt needs a decision',
  },
  wallet_top_ups_pending: {
    label: 'Wallet top-ups',
    href: '/wallet-top-ups',
    icon: PiggyBank,
    hint: 'Claims the verifier could not settle',
  },
  store_reviews_pending: {
    label: 'Store reviews',
    href: '/store-reviews',
    icon: ClipboardCheck,
    hint: 'New merchant registrations to approve',
  },
  change_requests_pending: {
    label: 'Change requests',
    href: '/change-requests',
    icon: FileDiff,
    hint: 'Live stores asking to change something',
  },
  holds_open: {
    label: 'Holds',
    href: '/holds',
    icon: ShieldAlert,
    hint: 'Transactions under fraud or dispute review',
  },
  marketplace_kyb_pending: {
    label: 'Marketplace KYB',
    href: '/marketplace/kyb',
    icon: BadgeCheck,
    hint: 'Sellers waiting on a KYB decision',
  },
};

function AttentionTile({ queue, count }: { queue: QueueMeta; count: number }) {
  const waiting = count > 0;
  const Icon = queue.icon;

  return (
    <Link
      href={queue.href}
      className={cn(
        'flex flex-col gap-2 rounded-xl border p-4 shadow-xs transition-colors',
        waiting
          ? 'border-yellow-200 bg-yellow-50/70 hover:bg-yellow-50 dark:border-yellow-900/70 dark:bg-yellow-950/30 dark:hover:bg-yellow-950/50'
          : 'border-border bg-card hover:bg-accent/40',
      )}
    >
      <span
        className={cn(
          'flex items-center gap-2 text-xs font-medium',
          waiting
            ? 'text-yellow-700 dark:text-yellow-500'
            : 'text-muted-foreground',
        )}
      >
        <Icon className="size-3.5 shrink-0" />
        <span className="min-w-0 flex-1 truncate">{queue.label}</span>
      </span>

      {/* Proportional figures, not tabular — the tiles do not form a column,
          and equal-width digits make a two-digit count look loose. */}
      <span
        className={cn(
          'text-2xl leading-none',
          waiting
            ? 'font-semibold text-foreground'
            : 'font-normal text-muted-foreground',
        )}
      >
        {formatCount(count)}
      </span>

      <span className="text-[0.6875rem] leading-snug text-muted-foreground">
        {waiting ? queue.hint : 'Nothing waiting'}
      </span>
    </Link>
  );
}

export function AttentionRow({ attention }: { attention: DashboardAttention }) {
  // Whatever keys this deployment carries, in the server's order. The
  // marketplace queue is ABSENT — not zero — while the flag is off, and a
  // permanent "0 KYB applications" tile is exactly the surface that rule is
  // about, so it is skipped rather than defaulted.
  const queues = DASHBOARD_ATTENTION_QUEUES.filter(
    (key) => attention[key] !== undefined,
  );

  return (
    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
      {queues.map((key) => (
        <AttentionTile
          key={key}
          queue={QUEUES[key]}
          count={attention[key] ?? 0}
        />
      ))}
    </div>
  );
}
