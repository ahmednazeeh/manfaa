'use client';

import type { SettlementPayment } from '@manfaa/api-client';
import { settlementSlipPath } from '@/lib/slip';
import { SlipFrame } from '@/components/admin/slip-frame';

/**
 * The merchant's uploaded transfer slip on a settlement payment, streamed
 * from the authorised admin route. The viewer itself is shared with the
 * wallet top-up queue (SlipFrame); this binds it to a settlement payment.
 */
export function SlipPreview({
  settlementId,
  payment,
}: {
  settlementId: number;
  payment: SettlementPayment;
}) {
  return (
    <SlipFrame
      path={
        payment.has_slip ? settlementSlipPath(settlementId, payment.id) : null
      }
      sizeBytes={payment.slip_size_bytes}
      alt={`Transfer slip for bank reference ${payment.bank_ref ?? 'unknown'}`}
      empty={{
        title: 'No slip on this payment',
        hint: 'Payments recorded from a bank statement carry no upload. Verify the bank reference against the statement before matching.',
      }}
    />
  );
}
