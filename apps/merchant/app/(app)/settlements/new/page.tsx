'use client';

import { RoleGate } from '@/components/app/role-gate';
import { SettlementWizard } from '@/components/settlement/settlement-wizard';

/**
 * The receipt-first settlement wizard (PLAN §1): choose transactions → see
 * the amount due, the platform's bank account and the reference → transfer
 * at your bank → upload the slip and submit. Submitting is what creates the
 * settlement, so nothing here can produce one without a receipt.
 *
 * Manager-or-owner work (PLAN §1) — submitting freezes lines and claims a
 * real transfer. The API gates the same POST with merchant.role:manager, so
 * this gate is courtesy, not security.
 */
export default function NewSettlementPage() {
  return (
    <RoleGate min="manager">
      <SettlementWizard />
    </RoleGate>
  );
}
