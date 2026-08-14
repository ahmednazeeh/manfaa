'use client';

import {
  type SettlementState,
  type TransactionState,
} from '@manfaa/api-client';
import { Badge, BadgeProps } from '@/components/ui/badge';

/**
 * State chips for the §6 state machines. Labels are the customer-honest
 * wording from the plan; colours come from the template's badge palette so
 * they hold up in light and dark mode.
 */

const TRANSACTION_STATES: Record<
  TransactionState,
  { label: string; variant: BadgeProps['variant'] }
> = {
  tracked: { label: 'Tracked', variant: 'secondary' },
  awaiting_validation: { label: 'Awaiting validation', variant: 'info' },
  payable_unfunded: { label: 'Payable', variant: 'warning' },
  on_hold: { label: 'On hold', variant: 'destructive' },
  confirmed: { label: 'Confirmed', variant: 'success' },
  paid: { label: 'Paid', variant: 'success' },
  reversed: { label: 'Reversed', variant: 'secondary' },
  written_off: { label: 'Written off', variant: 'destructive' },
};

export function TransactionStateBadge({ state }: { state: TransactionState }) {
  const meta = TRANSACTION_STATES[state];
  return (
    <Badge variant={meta.variant} appearance="light" size="sm">
      {meta.label}
    </Badge>
  );
}

const SETTLEMENT_STATES: Record<
  SettlementState,
  { label: string; variant: BadgeProps['variant'] }
> = {
  draft: { label: 'Draft', variant: 'secondary' },
  awaiting_payment: { label: 'Awaiting payment', variant: 'warning' },
  payment_review: { label: 'Payment review', variant: 'info' },
  settled: { label: 'Settled', variant: 'success' },
  partially_settled: { label: 'Partially settled', variant: 'info' },
  cancelled: { label: 'Cancelled', variant: 'destructive' },
};

export function SettlementStateBadge({ state }: { state: SettlementState }) {
  const meta = SETTLEMENT_STATES[state];
  return (
    <Badge variant={meta.variant} appearance="light" size="sm">
      {meta.label}
    </Badge>
  );
}

export function settlementStateLabel(state: SettlementState): string {
  return SETTLEMENT_STATES[state].label;
}
