import { Metadata } from 'next';
import { ClosureFlow } from '@/components/marketing/closure-flow';
import { LegalPage, LegalSection } from '@/components/marketing/legal-page';

export const metadata: Metadata = {
  title: 'Account Closure — Manfaa Merchant',
  description: 'Close a store with the registered contact number and an SMS code.',
};

export default function MerchantAccountDeletionPage() {
  return (
    <LegalPage title="Close a store or staff account" updated="17 August 2026">
      <ClosureFlow />

      <LegalSection heading="How closing works">
        <ul>
          <li>
            Closure is self-service: verify the store&rsquo;s registered
            contact number with the SMS code above, and each store on that
            number appears with its settlement position.
          </li>
          <li>
            <strong>Settle first:</strong> a store still owing cashback or
            fees cannot be closed — the panel stays open for settling,
            receipts and records until the balance is zero, then the Close
            button unlocks.
          </li>
          <li>
            On closure the store leaves the Manfaa app and map immediately,
            crediting stops, and every staff account is deactivated and
            signed out.
          </li>
        </ul>
      </LegalSection>

      <LegalSection heading="What is kept">
        <p>
          Transaction and settlement records are financial records and are
          retained for as long as the law requires — they are the shared
          ledger between the store, its customers&rsquo; cashback and
          Manfaa. Business details no longer appear anywhere public. Staff
          names and emails are anonymised on request after closure: write to{' '}
          <strong>support@manfaa.app</strong>.
        </p>
      </LegalSection>

      <LegalSection heading="Deleting a single staff account">
        <p>
          Store owners and managers remove staff in the panel or app under
          Manage Employees — deactivation is immediate. A former staff
          member can also ask us directly at{' '}
          <strong>support@manfaa.app</strong> to have their personal details
          (name and email) anonymised on a deactivated account; the actions
          they took while employed remain in the store&rsquo;s records,
          attributed to a deactivated account.
        </p>
      </LegalSection>
    </LegalPage>
  );
}
