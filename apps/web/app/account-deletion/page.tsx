import { Metadata } from 'next';
import { DeletionFlow } from '@/components/app/deletion-flow';
import { LegalPage, LegalSection } from '@/components/app/legal-page';

export const metadata: Metadata = {
  title: 'Account Deletion — Manfaa',
  description: 'Delete your Manfaa account with your phone and an SMS code.',
};

/**
 * The self-service web deletion path the app stores point at: phone + OTP,
 * no support ticket in the loop. The prose below the flow is the policy
 * side — what deleting costs and what is kept, anonymised.
 */
export default function AccountDeletionPage() {
  return (
    <LegalPage title="Delete your Manfaa account" updated="17 August 2026">
      <DeletionFlow />

      <LegalSection heading="How it works">
        <p>
          Deletion is self-service: enter your registered mobile number
          above, confirm the 6-digit code we text you, review what deleting
          means for your balance, and confirm. The account is deleted
          immediately — no email or support ticket needed. If you no longer
          control the registered number, contact{' '}
          <strong>support@manfaa.app</strong> and we will verify you another
          way.
        </p>
      </LegalSection>

      <LegalSection heading="Your balance">
        <ul>
          <li>
            Deleting <strong>lapses your remaining balance</strong> — both
            confirmed and pending cashback. The confirmation step shows you
            the exact amounts first.
          </li>
          <li>
            Want the money? Wait for the next payout run (payouts go out in
            the regular windows shown in the app) and delete after it
            arrives.
          </li>
        </ul>
      </LegalSection>

      <LegalSection heading="What is deleted, what is kept">
        <ul>
          <li>
            <strong>Deleted immediately:</strong> your name, mobile number,
            email, password, bank account details, avatar and notification
            tokens. Every signed-in session and device is signed out.
          </li>
          <li>
            <strong>Kept, anonymised:</strong> transaction and payout
            records, for as long as the law requires financial records to be
            retained. They are shared financial records with the stores that
            funded your cashback — after deletion they no longer contain
            anything that identifies you.
          </li>
        </ul>
        <p>
          Deletion is permanent: your member code, history and any lapsed
          balance cannot be restored. Your mobile number is freed, so you
          can register again later — as a brand-new account.
        </p>
      </LegalSection>
    </LegalPage>
  );
}
