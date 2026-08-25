'use client';

import Link from 'next/link';
import { getAdminTaxSettings } from '@manfaa/api-client';
import { useQuery } from '@tanstack/react-query';
import { ReceiptText } from 'lucide-react';
import { gstTreatmentPolicyLine } from '@/lib/gst';
import {
  Alert,
  AlertContent,
  AlertDescription,
  AlertIcon,
  AlertTitle,
} from '@/components/ui/alert';

/**
 * What the fee schedule does NOT say on its own: whether the platform is
 * charging GST on top of every fee in it.
 *
 * The §4 table quotes a fee and a merchant all-in rate, both of them
 * pre-tax. Once GST is switched on, the number a merchant actually owes is
 * larger than the all-in column (on top) or the fee income is smaller than
 * it looks (inclusive) — so the schedule states the tax regime it is read
 * under rather than leaving an admin to remember.
 *
 * Read is open to any admin; only the switch itself is superadmin, so this
 * renders for everyone and links to a page a plain admin cannot open. That
 * is deliberate: knowing the rate matters more than being able to change it.
 */
export function GstOnFeesNote() {
  const query = useQuery({
    queryKey: ['admin', 'tax-settings'],
    queryFn: ({ signal }) => getAdminTaxSettings({ signal }),
  });

  const tax = query.data?.data;

  // Silent while loading or if the read fails — the schedule below is still
  // correct and a broken banner would only distract from it.
  if (!tax) {
    return null;
  }

  if (!tax.gst_enabled) {
    return (
      <Alert variant="secondary" appearance="light" size="sm" className="mb-5">
        <AlertIcon>
          <ReceiptText />
        </AlertIcon>
        <AlertDescription>
          No GST is charged on the fees below — a merchant owes exactly the
          cashback plus the fee. Turning GST on is a{' '}
          <Link href="/settings/tax" className="underline">
            separate setting
          </Link>
          , and it prices new sales only.
        </AlertDescription>
      </Alert>
    );
  }

  return (
    <Alert variant="info" appearance="light" className="mb-5">
      <AlertIcon>
        <ReceiptText />
      </AlertIcon>
      <AlertContent>
        <AlertTitle>
          GST of {tax.gst_rate_percent}% applies to every fee below
        </AlertTitle>
        <AlertDescription>
          {gstTreatmentPolicyLine(tax.fee_treatment)}
          {tax.fee_treatment === 'on_top' ? (
            <>
              {' '}
              — a merchant owes the fee shown <em>plus</em> {
                tax.gst_rate_percent
              }
              % of it, so the merchant all-in column below understates the real
              bill.
            </>
          ) : (
            <>
              {' '}
              — a merchant owes exactly the fee shown; {tax.gst_rate_percent}%
              of it is tax carved out of Manfaa’s own fee revenue, so the
              columns below overstate what the platform keeps.
            </>
          )}{' '}
          Each sale is stamped with the rate it was priced under, so this only
          describes sales made from{' '}
          <Link href="/settings/tax" className="underline">
            the moment charging started
          </Link>
          .
        </AlertDescription>
      </AlertContent>
    </Alert>
  );
}
