'use client';

import {
  type PromotionStatus,
  type SettlementState,
  type TransactionState,
} from '@manfaa/api-client';
import { useTranslation } from 'react-i18next';
import {
  promotionStatusLabel,
  reasonCodeLabel,
  settlementStateLabel,
  transactionStateLabel,
} from '@/lib/labels';
import { Badge, BadgeProps } from '@/components/ui/badge';

/**
 * State chips for the §6 state machines. The WORDS live in lib/labels.ts
 * (en + dv, exhaustive per union — PLAN §13b task #22: no raw snake_case in
 * any UI); only the colour lives here, from the template's badge palette so
 * it holds up in light and dark mode.
 */

const TRANSACTION_VARIANTS: Record<TransactionState, BadgeProps['variant']> = {
  tracked: 'secondary',
  awaiting_validation: 'info',
  payable_unfunded: 'warning',
  on_hold: 'destructive',
  confirmed: 'success',
  paid: 'success',
  reversed: 'secondary',
  written_off: 'destructive',
};

export function TransactionStateBadge({ state }: { state: TransactionState }) {
  const { t } = useTranslation();
  return (
    <Badge variant={TRANSACTION_VARIANTS[state]} appearance="light" size="sm">
      {transactionStateLabel(t, state)}
    </Badge>
  );
}

/**
 * The state qualifier that sits under the chip ("Paid by store", "Below
 * minimum sale"), in the shopkeeper's language. Renders nothing when the row
 * carries no reason_code — a clean pending sale has no qualifier (§9.2).
 */
export function TransactionReasonLine({
  code,
  className,
}: {
  code: string | null;
  className?: string;
}) {
  const { t } = useTranslation();
  const label = reasonCodeLabel(t, code);
  return label === null ? null : <span className={className}>{label}</span>;
}

const SETTLEMENT_VARIANTS: Record<SettlementState, BadgeProps['variant']> = {
  draft: 'secondary',
  awaiting_payment: 'warning',
  payment_review: 'info',
  settled: 'success',
  partially_settled: 'info',
  cancelled: 'destructive',
};

export function SettlementStateBadge({ state }: { state: SettlementState }) {
  const { t } = useTranslation();
  return (
    <Badge variant={SETTLEMENT_VARIANTS[state]} appearance="light" size="sm">
      {settlementStateLabel(t, state)}
    </Badge>
  );
}

const PROMOTION_VARIANTS: Record<PromotionStatus, BadgeProps['variant']> = {
  draft: 'secondary',
  published: 'info',
  ended: 'secondary',
  cancelled: 'destructive',
};

/** `live` = published AND the window covers now (the API's is_live flag). */
export function PromotionStatusBadge({
  status,
  live = false,
}: {
  status: PromotionStatus;
  live?: boolean;
}) {
  const { t } = useTranslation();
  return (
    <Badge
      variant={live ? 'success' : PROMOTION_VARIANTS[status]}
      appearance="light"
      size="sm"
    >
      {promotionStatusLabel(t, status, live)}
    </Badge>
  );
}
