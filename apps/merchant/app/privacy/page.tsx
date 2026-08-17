import { Metadata } from 'next';
import { LegalPage, LegalSection } from '@/components/marketing/legal-page';

export const metadata: Metadata = {
  title: 'Privacy Policy — Manfaa Merchant',
  description: 'How Manfaa handles merchant and staff information.',
};

export default function MerchantPrivacyPage() {
  return (
    <LegalPage title="Merchant Privacy Policy" updated="17 August 2026">
      <LegalSection heading="Who this covers">
        <p>
          This policy covers information Manfaa (operated by Manfaa Pvt Ltd,
          Malé, Republic of Maldives) holds about partner stores and the
          people who run them: owners and staff using the merchant panel and
          the Manfaa Merchant app. Members of the shopping public are covered
          by the separate customer privacy policy on manfaa.app. Questions:{' '}
          <strong>support@manfaa.app</strong>.
        </p>
      </LegalSection>

      <LegalSection heading="What we collect">
        <ul>
          <li>
            <strong>Business details</strong> — store name (Latin and
            Dhivehi), registration and TIN numbers, category, sales channel,
            contact details, website, logo, branch names and locations, and
            the cashback terms you publish.
          </li>
          <li>
            <strong>Settlement details</strong> — the bank account you settle
            from, settlement records, uploaded transfer slips, and your
            Manfaa wallet balance and movements.
          </li>
          <li>
            <strong>Staff accounts</strong> — each staff member&rsquo;s name,
            email, role and activity. Staff accounts are created and managed
            by the store; the store is responsible for telling its staff
            about this policy.
          </li>
          <li>
            <strong>Transaction records</strong> — every sale you credit:
            invoice number, amounts, rate, fees and status.
          </li>
          <li>
            <strong>Device data</strong> — push notification tokens for
            signed-in staff who enable notifications in the merchant app.
          </li>
        </ul>
      </LegalSection>

      <LegalSection heading="How we use it">
        <ul>
          <li>
            To run your storefront on Manfaa: your business profile, rates,
            terms, logo and branch locations are shown publicly to shoppers.
          </li>
          <li>
            To operate crediting, validation, settlement and the prompt-payment
            discount, and to send settlement reminders and deadline
            notifications to staff with the relevant permission.
          </li>
          <li>To keep the platform&rsquo;s financial records accurate.</li>
          <li>To comply with the law of the Maldives.</li>
        </ul>
      </LegalSection>

      <LegalSection heading="Customer data you receive">
        <p>
          When crediting a sale, your till shows the shopper&rsquo;s name and
          member code so the right person is credited.{' '}
          <strong>
            You may use that information only to operate cashback
          </strong>{' '}
          — verifying, crediting and correcting sales — never for marketing,
          profiling or any other purpose, and you must not copy it out of the
          platform.
        </p>
      </LegalSection>

      <LegalSection heading="Retention and closure">
        <p>
          Business, transaction and settlement records are financial records
          and are retained as required for auditing and by law, including
          after a store leaves the platform. Staff personal accounts are
          removed on request once the store&rsquo;s account is closed — see
          the <a className="underline hover:text-foreground" href="/account-deletion">account closure page</a>.
        </p>
      </LegalSection>
    </LegalPage>
  );
}
