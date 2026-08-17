import { Metadata } from 'next';
import { LegalPage, LegalSection } from '@/components/app/legal-page';

export const metadata: Metadata = {
  title: 'Account Deletion — Manfaa',
  description: 'How to delete your Manfaa account and what happens to your data.',
};

/**
 * The web deletion path required by the app stores (Play policy explicitly
 * wants a URL where a member can request deletion without the app).
 * Request-based for now; the in-app one-tap flow is tracked in the PLAN's
 * store-readiness work.
 */
export default function AccountDeletionPage() {
  return (
    <LegalPage title="Delete your Manfaa account" updated="17 August 2026">
      <LegalSection heading="How to request deletion">
        <p>
          Email <strong>support@manfaa.app</strong> from any address with the
          subject &ldquo;Delete my account&rdquo;, including your{' '}
          <strong>registered mobile number</strong> and your{' '}
          <strong>member code</strong> (shown on your Manfaa home screen). To
          protect your account we confirm the request with a code sent to
          your registered number before anything is deleted.
        </p>
        <p>
          Deletion is completed within <strong>30 days</strong> of
          confirmation, and we email or message you when it is done.
        </p>
      </LegalSection>

      <LegalSection heading="Before you delete — your balance">
        <ul>
          <li>
            <strong>Confirmed cashback</strong> at or above the payout
            minimum is paid out to your registered bank account in the final
            payout run before deletion. Tell us in your request if your bank
            details need updating first.
          </li>
          <li>
            Confirmed amounts below the payout minimum, and{' '}
            <strong>pending</strong> cashback that has not yet been
            confirmed by the store, lapse when the account closes and cannot
            be paid later.
          </li>
        </ul>
      </LegalSection>

      <LegalSection heading="What is deleted, what is kept">
        <ul>
          <li>
            <strong>Deleted:</strong> your name, mobile number, password,
            bank account details, push notification tokens, and your member
            code&rsquo;s link to you. You stop receiving all notifications.
          </li>
          <li>
            <strong>Kept, anonymised:</strong> transaction and payout records.
            These are financial records shared with the stores that funded
            your cashback and are required for auditing and the
            stores&rsquo; own settlement accounts — after deletion they no
            longer identify you.
          </li>
        </ul>
        <p>
          Deletion is permanent: your member code, history and any lapsed
          balance cannot be restored. You can register again later with the
          same mobile number, but it starts as a brand-new account.
        </p>
      </LegalSection>
    </LegalPage>
  );
}
