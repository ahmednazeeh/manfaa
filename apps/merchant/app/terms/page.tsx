import { Metadata } from 'next';
import { LegalPage, LegalSection } from '@/components/marketing/legal-page';

export const metadata: Metadata = {
  title: 'Terms of Service — Manfaa Merchant',
  description: 'The terms that govern partner stores on Manfaa.',
};

export default function MerchantTermsPage() {
  return (
    <LegalPage title="Merchant Terms of Service" updated="17 August 2026">
      <LegalSection heading="The agreement">
        <p>
          These terms govern your store&rsquo;s participation in Manfaa, the
          cashback platform operated by Manfaa Pvt Ltd, Malé, Republic of
          Maldives. Registering a store, or crediting a sale, means the
          business accepts them.
        </p>
      </LegalSection>

      <LegalSection heading="Going live">
        <ul>
          <li>
            You provide accurate business details during setup. Stores go
            live after Manfaa&rsquo;s review and can be suspended or removed
            for misrepresentation.
          </li>
          <li>
            <strong>Your cashback terms are a promise to shoppers.</strong>{' '}
            The eligibility wording you publish (what part of a bill earns
            cashback) is shown to customers exactly as you wrote it, and you
            must honour it at the till.
          </li>
        </ul>
      </LegalSection>

      <LegalSection heading="Rates, fees and crediting">
        <ul>
          <li>
            You choose your cashback rate within the platform&rsquo;s
            published range. Each sale you credit earns the customer cashback
            at your rate in force at the time of sale, plus a platform fee
            per the published fee schedule — the current schedule and your
            all-in cost are always shown in your dashboard before you
            commit.
          </li>
          <li>
            Credit sales honestly: real invoices, correct amounts, at the
            time of sale. Corrections and cancellations are available for a
            limited window and are logged.
          </li>
          <li>
            Fabricated sales, self-crediting or collusion to farm cashback
            are grounds for immediate suspension and recovery of losses.
          </li>
        </ul>
      </LegalSection>

      <LegalSection heading="Settlement">
        <ul>
          <li>
            Credited cashback and fees become payable to Manfaa and must be
            settled within the settlement window shown in your dashboard,
            counted from each sale. Settling early earns the prompt-payment
            discount on the platform fee, as displayed at the time of
            settlement.
          </li>
          <li>
            You settle by bank transfer (uploading the transfer slip) or
            from your Manfaa wallet. A settlement is complete when Manfaa
            verifies the payment.
          </li>
          <li>
            Overdue balances trigger escalating reminders and, past the
            deadline, automatic suspension of crediting until you settle.
            Settling, receipts and your records remain available while
            suspended — suspension never locks you out of putting things
            right.
          </li>
          <li>
            Customers&rsquo; earned cashback on validated sales is owed
            regardless of your settlement status.
          </li>
        </ul>
      </LegalSection>

      <LegalSection heading="Staff and security">
        <p>
          You create and manage your staff accounts and their roles. Actions
          taken by your staff accounts are the store&rsquo;s actions. Keep
          access current — remove people who leave, and use roles so each
          person holds only the permissions their job needs.
        </p>
      </LegalSection>

      <LegalSection heading="Taxes, liability and changes">
        <ul>
          <li>
            You are responsible for your own taxes, including any GST due on
            your sales; platform fees are shown with any applicable GST.
          </li>
          <li>
            Manfaa operates the programme and ledger; it is not a party to
            the sale between you and your customer.
          </li>
          <li>
            Either side may end participation with outstanding balances
            settled first — see the{' '}
            <a className="underline hover:text-foreground" href="/account-deletion">account closure page</a>.
            Meaningful changes to these terms are announced in the panel
            before they apply.
          </li>
          <li>These terms are governed by the law of the Republic of Maldives.</li>
        </ul>
      </LegalSection>
    </LegalPage>
  );
}
