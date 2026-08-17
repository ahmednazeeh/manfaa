import { Metadata } from 'next';
import { LegalPage, LegalSection } from '@/components/app/legal-page';

export const metadata: Metadata = {
  title: 'Terms of Service — Manfaa',
  description: 'The terms that govern your Manfaa membership.',
};

export default function TermsPage() {
  return (
    <LegalPage title="Terms of Service" updated="17 August 2026">
      <LegalSection heading="What Manfaa is">
        <p>
          Manfaa (operated by Manfaa Pvt Ltd, Malé, Republic of Maldives) lets
          you earn cashback at partner stores. The cashback is funded by the
          store you shop at — Manfaa operates the programme, keeps the
          ledger, and pays out what you earn. By creating an account you
          accept these terms.
        </p>
      </LegalSection>

      <LegalSection heading="Your account">
        <ul>
          <li>
            One account per person, registered to your own Maldivian mobile
            number. Keep your sign-in details to yourself — activity on your
            account is treated as yours.
          </li>
          <li>You must be at least 16 to hold an account.</li>
        </ul>
      </LegalSection>

      <LegalSection heading="How cashback works">
        <ul>
          <li>
            Present your member code or QR at a partner store; the store
            records your purchase and credits cashback at its published rate.
          </li>
          <li>
            <strong>Each store decides what earns cashback.</strong> A
            store&rsquo;s own eligibility terms are shown on its Manfaa page
            and apply exactly as written — parts of a bill (for example
            service charge or gift cards) may be excluded.
          </li>
          <li>
            Cashback starts as <strong>pending</strong> and becomes{' '}
            <strong>confirmed</strong> once the store&rsquo;s validation
            period passes or the store confirms the sale. Only confirmed
            cashback can be paid out.
          </li>
          <li>
            If a sale is cancelled, refunded or corrected, the cashback on it
            is reversed. The rate applied is the one in force at the time of
            the sale.
          </li>
        </ul>
      </LegalSection>

      <LegalSection heading="Payouts">
        <ul>
          <li>
            Confirmed cashback is paid to the Maldivian bank account you
            register, in rufiyaa, during regular payout windows shown in the
            app.
          </li>
          <li>
            Payouts start at the minimum amount shown in the app; anything
            below the minimum simply carries over to the next window.
          </li>
          <li>
            Cashback is a shopping rebate: it earns no interest, and your
            balance is not a bank deposit, e-money or investment.
          </li>
        </ul>
      </LegalSection>

      <LegalSection heading="Fair use">
        <p>
          The programme exists to reward real shopping. We may withhold or
          reverse cashback, and suspend or close accounts, where we find
          fabricated transactions, collusion with a store to generate
          cashback without genuine sales, multiple accounts for one person,
          or other abuse. Honest members will never notice this section.
        </p>
      </LegalSection>

      <LegalSection heading="Ending your membership">
        <p>
          You can stop using Manfaa at any time and delete your account —
          the process, including what happens to an unpaid balance, is
          described on our{' '}
          <a className="underline hover:text-foreground" href="/account-deletion">account deletion page</a>. We may close
          accounts that break these terms.
        </p>
      </LegalSection>

      <LegalSection heading="Liability and changes">
        <ul>
          <li>
            Stores are independent businesses: the goods and services you buy
            are between you and the store. Manfaa&rsquo;s responsibility is
            the correct operation of the cashback programme.
          </li>
          <li>
            We may update these terms; meaningful changes will be announced
            in the app before they apply. Continued use after a change means
            you accept it.
          </li>
          <li>These terms are governed by the law of the Republic of Maldives.</li>
        </ul>
      </LegalSection>
    </LegalPage>
  );
}
