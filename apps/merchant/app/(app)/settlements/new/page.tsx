'use client';

import { RoleGate } from '@/components/app/role-gate';
import { SettlementWizard } from '@/components/settlement/settlement-wizard';

/**
 * The receipt-first settlement wizard (PLAN §1): choose transactions → see
 * the amount due, the platform's bank account and the reference → transfer
 * at your bank → upload the slip and submit. Submitting is what creates the
 * settlement, so nothing here can produce one without a receipt.
 *
 * Gated on the act the last step performs — submitting freezes lines and
 * claims a real transfer — rather than on the preview the earlier steps
 * read: a reader who could only preview would reach the final button and be
 * refused there. The API gates the same POST with
 * `merchant.can:settlements.create`, so this is courtesy, not security.
 */
export default function NewSettlementPage() {
  return (
    <RoleGate permission="settlements.create">
      <SettlementWizard />
    </RoleGate>
  );
}
