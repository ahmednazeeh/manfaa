import type {
  CustomerStatus,
  MerchantStatus,
  PayoutBatchState,
  PayoutItemState,
  SettlementPaymentState,
  SettlementState,
  TransactionState,
  WalletTopUpState,
} from '@manfaa/api-client';
import {
  customerStatusLabel,
  merchantStatusLabel,
  payoutBatchStateLabel,
  payoutItemStateLabel,
  reasonCodeLabel,
  settlementPaymentStateLabel,
  settlementStateLabel,
  transactionStateLabel,
  walletTopUpStateLabel,
} from '@/lib/labels';
import { Badge, BadgeDot, type BadgeProps } from '@/components/ui/badge';

/**
 * State chips for every Phase 1 state machine. One mapping per machine so a
 * screen can never render a state the API cannot produce — and the WORDS
 * come from lib/labels.ts (exhaustive per union), so only the colour is
 * decided here.
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

const SETTLEMENT_VARIANTS: Record<SettlementState, BadgeProps['variant']> = {
  draft: 'secondary',
  awaiting_payment: 'warning',
  payment_review: 'info',
  settled: 'success',
  partially_settled: 'warning',
  cancelled: 'secondary',
};

export function SettlementStateBadge({ state }: { state: SettlementState }) {
  return (
    <StateChip
      label={settlementStateLabel(state)}
      style={{ variant: SETTLEMENT_VARIANTS[state], appearance: 'light' }}
    />
  );
}

const PAYMENT_VARIANTS: Record<SettlementPaymentState, BadgeProps['variant']> =
  {
    pending: 'warning',
    matched: 'success',
    rejected: 'destructive',
  };

export function PaymentStateBadge({
  state,
}: {
  state: SettlementPaymentState;
}) {
  return (
    <StateChip
      label={settlementPaymentStateLabel(state)}
      style={{ variant: PAYMENT_VARIANTS[state], appearance: 'light' }}
    />
  );
}

/** Same colours as a settlement payment: the two queues are one job. */
const TOP_UP_VARIANTS: Record<WalletTopUpState, BadgeProps['variant']> = {
  pending: 'warning',
  matched: 'success',
  rejected: 'destructive',
};

export function TopUpStateBadge({ state }: { state: WalletTopUpState }) {
  return (
    <StateChip
      label={walletTopUpStateLabel(state)}
      style={{ variant: TOP_UP_VARIANTS[state], appearance: 'light' }}
    />
  );
}

const TRANSACTION_VARIANTS: Record<TransactionState, BadgeProps['variant']> = {
  tracked: 'secondary',
  awaiting_validation: 'secondary',
  payable_unfunded: 'warning',
  on_hold: 'destructive',
  confirmed: 'success',
  paid: 'success',
  reversed: 'secondary',
  written_off: 'destructive',
};

export function TransactionStateBadge({ state }: { state: TransactionState }) {
  return (
    <StateChip
      label={transactionStateLabel(state)}
      style={{ variant: TRANSACTION_VARIANTS[state], appearance: 'light' }}
    />
  );
}

/**
 * The state qualifier that sits beside the chip ("Paid by store", "Backdated
 * — cannot be reversed"). Renders nothing when the row carries no
 * reason_code: a clean pending sale has no qualifier (§9.2).
 */
export function TransactionReasonLine({
  code,
  className,
}: {
  code: string | null;
  className?: string;
}) {
  const label = reasonCodeLabel(code);
  return label === null ? null : <span className={className}>{label}</span>;
}

const PAYOUT_BATCH_VARIANTS: Record<PayoutBatchState, BadgeProps['variant']> = {
  draft: 'secondary',
  approved: 'info',
  processing: 'warning',
  sent: 'info',
  completed: 'success',
  partially_failed: 'destructive',
  cancelled: 'secondary',
};

export function PayoutBatchStateBadge({ state }: { state: PayoutBatchState }) {
  return (
    <StateChip
      label={payoutBatchStateLabel(state)}
      style={{ variant: PAYOUT_BATCH_VARIANTS[state], appearance: 'light' }}
    />
  );
}

const PAYOUT_ITEM_VARIANTS: Record<PayoutItemState, BadgeProps['variant']> = {
  pending: 'secondary',
  sent: 'info',
  paid: 'success',
  failed: 'destructive',
};

export function PayoutItemStateBadge({ state }: { state: PayoutItemState }) {
  return (
    <StateChip
      label={payoutItemStateLabel(state)}
      style={{ variant: PAYOUT_ITEM_VARIANTS[state], appearance: 'light' }}
    />
  );
}

const MERCHANT_VARIANTS: Record<MerchantStatus, BadgeProps['variant']> = {
  draft: 'secondary',
  pending_review: 'warning',
  rejected: 'destructive',
  active: 'success',
  suspended: 'destructive',
  closed: 'secondary',
};

/**
 * Typed against MerchantStatus rather than `string`: the old signature fell
 * back to rendering the raw status when it did not recognise one, which is
 * exactly the leak this pass exists to remove.
 */
export function MerchantStatusBadge({ status }: { status: MerchantStatus }) {
  return (
    <StateChip
      label={merchantStatusLabel(status)}
      style={{ variant: MERCHANT_VARIANTS[status], appearance: 'light' }}
    />
  );
}

const CUSTOMER_VARIANTS: Record<CustomerStatus, BadgeProps['variant']> = {
  active: 'success',
  suspended: 'destructive',
  closed: 'secondary',
};

export function CustomerStatusBadge({ status }: { status: CustomerStatus }) {
  return (
    <StateChip
      label={customerStatusLabel(status)}
      style={{ variant: CUSTOMER_VARIANTS[status], appearance: 'light' }}
    />
  );
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
