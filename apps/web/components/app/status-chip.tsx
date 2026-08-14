'use client';

import {
  type ClaimState,
  type CustomerTransactionStatus,
} from '@manfaa/api-client';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';

type BadgeVariant =
  'primary' | 'secondary' | 'success' | 'warning' | 'info' | 'destructive';

/** §6 customer status mapping — colours match what each state means to the customer. */
const STATUS_VARIANTS: Record<CustomerTransactionStatus, BadgeVariant> = {
  pending: 'warning',
  confirmed: 'success',
  paid: 'primary',
  reversed: 'secondary',
  unpaid: 'destructive',
};

export function TransactionStatusChip({
  status,
}: {
  status: CustomerTransactionStatus;
}) {
  const { t } = useTranslation();
  return (
    <Badge variant={STATUS_VARIANTS[status]} appearance="light" size="sm">
      {t(`status.${status}`)}
    </Badge>
  );
}

const CLAIM_VARIANTS: Record<ClaimState, BadgeVariant> = {
  open: 'info',
  in_review: 'warning',
  approved: 'success',
  rejected: 'destructive',
};

export function ClaimStateChip({ state }: { state: ClaimState }) {
  const { t } = useTranslation();
  return (
    <Badge variant={CLAIM_VARIANTS[state]} appearance="light" size="sm">
      {t(`claims.state.${state}`)}
    </Badge>
  );
}
