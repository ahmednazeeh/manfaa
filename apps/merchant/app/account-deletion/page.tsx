import { Metadata } from 'next';
import { LegalPage, LegalSection } from '@/components/marketing/legal-page';

export const metadata: Metadata = {
  title: 'Account Closure — Manfaa Merchant',
  description: 'How to close a store account or delete a staff account.',
};

export default function MerchantAccountDeletionPage() {
  return (
    <LegalPage title="Close a store or staff account" updated="17 August 2026">
      <LegalSection heading="Closing your store's account">
        <p>
          Email <strong>support@manfaa.app</strong> from the store
          owner&rsquo;s registered email with the subject &ldquo;Close my
          store&rdquo;, naming the store. We confirm the request with the
          owner before anything changes.
        </p>
        <ul>
          <li>
            <strong>Settle first:</strong> outstanding cashback and fees must
            be settled before closure completes. Your panel access stays open
            for settling, receipts and records throughout.
          </li>
          <li>
            On closure the store leaves the Manfaa app and map, crediting
            stops, and all staff accounts are deactivated. Closure is
            completed within 30 days of the final settlement.
          </li>
          <li>
            Transaction and settlement records are financial records and are
            retained as required for auditing and by law; staff personal
            details are deleted on request after closure.
          </li>
        </ul>
      </LegalSection>

      <LegalSection heading="Deleting a single staff account">
        <p>
          Store owners and managers remove staff in the panel or app under
          Manage Employees — deactivation is immediate. A former staff
          member can also ask us directly at{' '}
          <strong>support@manfaa.app</strong> to have their personal details
          (name and email) deleted from a deactivated account; the actions
          they took while employed remain in the store&rsquo;s records,
          attributed to a deactivated account.
        </p>
      </LegalSection>
    </LegalPage>
  );
}
