import type { SettlementPayment } from '@manfaa/api-client';
import { Ban } from 'lucide-react';
import { formatDateTime } from '@/lib/format';
import {
  Alert,
  AlertContent,
  AlertDescription,
  AlertIcon,
  AlertTitle,
} from '@/components/ui/alert';

/**
 * Why a cancelled batch was cancelled. A settlement is only ever shown as
 * "rejected" when a refused payment says so — a plain cancellation is not a
 * refusal of anything and is never dressed up as one.
 */
export function RejectionNotice({ payment }: { payment: SettlementPayment }) {
  return (
    <Alert variant="destructive" appearance="light" className="mb-5">
      <AlertIcon>
        <Ban />
      </AlertIcon>
      <AlertContent>
        <AlertTitle>
          Receipt rejected {formatDateTime(payment.rejected_at)}
        </AlertTitle>
        <AlertDescription>
          <p className="whitespace-pre-wrap">{payment.rejection_reason}</p>
          <p className="mt-1.5 text-xs">
            Bank reference {payment.bank_ref ?? '—'}. The batch was cancelled
            and its lines released — those transactions are payable again and
            the merchant can submit a new settlement.
          </p>
        </AlertDescription>
      </AlertContent>
    </Alert>
  );
}
