import type {
  PayoutBatchState,
  PayoutItemState,
  SettlementPaymentState,
  SettlementState,
  TransactionState,
} from '@manfaa/api-client';
import { Badge, BadgeDot, type BadgeProps } from '@/components/ui/badge';

/**
 * State chips for every Phase 1 state machine. One mapping per machine so a
 * screen can never render a state the API cannot produce.
 */

type ChipStyle = Pick<BadgeProps, 'variant' | 'appearance'>;

function StateChip({ label, style }: { label: string; style: ChipStyle }) {
  return (
    <Badge variant={style.variant} appearance={style.appearance} size="sm">
      <BadgeDot />
      {label}
    </Badge>
  );
}

const SETTLEMENT_STATES: Record<
  SettlementState,
  { label: string } & ChipStyle
> = {
  draft: { label: 'Draft', variant: 'secondary', appearance: 'light' },
  awaiting_payment: {
    label: 'Awaiting payment',
    variant: 'warning',
    appearance: 'light',
  },
  payment_review: {
    label: 'Payment review',
    variant: 'info',
    appearance: 'light',
  },
  settled: { label: 'Settled', variant: 'success', appearance: 'light' },
  partially_settled: {
    label: 'Partially settled',
    variant: 'warning',
    appearance: 'light',
  },
  cancelled: {
    label: 'Cancelled',
    variant: 'secondary',
    appearance: 'light',
  },
};

export function SettlementStateBadge({ state }: { state: SettlementState }) {
  const { label, ...style } = SETTLEMENT_STATES[state];
  return <StateChip label={label} style={style} />;
}

const PAYMENT_STATES: Record<
  SettlementPaymentState,
  { label: string } & ChipStyle
> = {
  pending: { label: 'Pending match', variant: 'warning', appearance: 'light' },
  matched: { label: 'Matched', variant: 'success', appearance: 'light' },
  rejected: {
    label: 'Rejected',
    variant: 'destructive',
    appearance: 'light',
  },
};

export function PaymentStateBadge({
  state,
}: {
  state: SettlementPaymentState;
}) {
  const { label, ...style } = PAYMENT_STATES[state];
  return <StateChip label={label} style={style} />;
}

const TRANSACTION_STATES: Record<
  TransactionState,
  { label: string } & ChipStyle
> = {
  tracked: { label: 'Tracked', variant: 'secondary', appearance: 'light' },
  awaiting_validation: {
    label: 'Awaiting validation',
    variant: 'secondary',
    appearance: 'light',
  },
  payable_unfunded: {
    label: 'Payable (unfunded)',
    variant: 'warning',
    appearance: 'light',
  },
  on_hold: { label: 'On hold', variant: 'destructive', appearance: 'light' },
  confirmed: { label: 'Confirmed', variant: 'success', appearance: 'light' },
  paid: { label: 'Paid', variant: 'success', appearance: 'light' },
  reversed: { label: 'Reversed', variant: 'secondary', appearance: 'light' },
  written_off: {
    label: 'Written off',
    variant: 'destructive',
    appearance: 'light',
  },
};

export function TransactionStateBadge({ state }: { state: TransactionState }) {
  const { label, ...style } = TRANSACTION_STATES[state];
  return <StateChip label={label} style={style} />;
}

const PAYOUT_BATCH_STATES: Record<
  PayoutBatchState,
  { label: string } & ChipStyle
> = {
  draft: { label: 'Draft', variant: 'secondary', appearance: 'light' },
  approved: { label: 'Approved', variant: 'info', appearance: 'light' },
  processing: { label: 'Processing', variant: 'warning', appearance: 'light' },
  sent: { label: 'Sent', variant: 'info', appearance: 'light' },
  completed: { label: 'Completed', variant: 'success', appearance: 'light' },
  partially_failed: {
    label: 'Partially failed',
    variant: 'destructive',
    appearance: 'light',
  },
  cancelled: { label: 'Cancelled', variant: 'secondary', appearance: 'light' },
};

export function PayoutBatchStateBadge({ state }: { state: PayoutBatchState }) {
  const { label, ...style } = PAYOUT_BATCH_STATES[state];
  return <StateChip label={label} style={style} />;
}

const PAYOUT_ITEM_STATES: Record<
  PayoutItemState,
  { label: string } & ChipStyle
> = {
  pending: { label: 'Pending', variant: 'secondary', appearance: 'light' },
  sent: { label: 'Sent', variant: 'info', appearance: 'light' },
  paid: { label: 'Paid', variant: 'success', appearance: 'light' },
  failed: { label: 'Failed', variant: 'destructive', appearance: 'light' },
};

export function PayoutItemStateBadge({ state }: { state: PayoutItemState }) {
  const { label, ...style } = PAYOUT_ITEM_STATES[state];
  return <StateChip label={label} style={style} />;
}

const MERCHANT_STATUSES: Record<string, { label: string } & ChipStyle> = {
  active: { label: 'Active', variant: 'success', appearance: 'light' },
  suspended: {
    label: 'Suspended',
    variant: 'destructive',
    appearance: 'light',
  },
  closed: { label: 'Closed', variant: 'secondary', appearance: 'light' },
};

export function MerchantStatusBadge({ status }: { status: string }) {
  const entry = MERCHANT_STATUSES[status] ?? {
    label: status,
    variant: 'secondary' as const,
    appearance: 'light' as const,
  };
  const { label, ...style } = entry;
  return <StateChip label={label} style={style} />;
}

export function ReconciliationStatusBadge({
  status,
}: {
  status: 'ok' | 'divergent';
}) {
  return status === 'ok' ? (
    <StateChip label="OK" style={{ variant: 'success', appearance: 'light' }} />
  ) : (
    <StateChip
      label="Divergent"
      style={{ variant: 'destructive', appearance: 'light' }}
    />
  );
}
