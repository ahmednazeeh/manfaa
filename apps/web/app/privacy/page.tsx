import { Metadata } from 'next';
import { LegalPage, LegalSection } from '@/components/app/legal-page';

export const metadata: Metadata = {
  title: 'Privacy Policy — Manfaa',
  description: 'How Manfaa collects, uses and protects your information.',
};

export default function PrivacyPage() {
  return (
    <LegalPage title="Privacy Policy" updated="17 August 2026">
      <LegalSection heading="Who we are">
        <p>
          Manfaa (operated by Manfaa Pvt Ltd, Malé, Republic of Maldives) is a
          cashback rewards platform: you shop at partner stores, the store
          credits your purchase, and you earn cashback paid out in Maldivian
          rufiyaa. This policy explains what information we hold about you as
          a Manfaa member and how we use it. For questions or requests, write
          to <strong>support@manfaa.app</strong>.
        </p>
      </LegalSection>

      <LegalSection heading="What we collect">
        <ul>
          <li>
            <strong>Account details</strong> — your name, Maldivian mobile
            number (verified by SMS code) and password. Your account is
            assigned a member code, which is how stores identify you at the
            till.
          </li>
          <li>
            <strong>Payout details</strong> — the bank account name, bank and
            account number you provide so we can pay out your cashback. We
            never ask for or store card numbers.
          </li>
          <li>
            <strong>Transaction records</strong> — created when a partner
            store credits a purchase: the store, invoice number, eligible
            amount, cashback earned and its status (pending, confirmed,
            reversed, paid).
          </li>
          <li>
            <strong>Device and preference data</strong> — a push notification
            token if you enable notifications in the mobile app, and your
            language and theme choices.
          </li>
          <li>
            <strong>Location, only when you ask</strong> — the &ldquo;Near
            you&rdquo; feature uses your device location to sort nearby
            stores at the moment you use it. We do not track or store your
            location history.
          </li>
        </ul>
      </LegalSection>

      <LegalSection heading="How we use it">
        <ul>
          <li>To operate your cashback balance and pay out what you earn.</li>
          <li>
            To let a store verify you at the till: when you present your
            member code or QR, that store sees your name and member code so
            the right person is credited.
          </li>
          <li>
            To send service notifications — cashback earned or confirmed,
            payouts made — and SMS codes for signing in.
          </li>
          <li>To prevent fraud and abuse of the rewards programme.</li>
          <li>To comply with law and keep accurate financial records.</li>
        </ul>
        <p>
          We do not sell your personal information, and we do not share it
          with anyone for their marketing.
        </p>
      </LegalSection>

      <LegalSection heading="Who we share it with">
        <ul>
          <li>
            <strong>The store you shop at</strong> — sees your name and
            member code when crediting your purchase, and the transaction it
            created. Stores may use this only to operate cashback, not for
            marketing.
          </li>
          <li>
            <strong>Service providers</strong> — our SMS gateway (to deliver
            sign-in codes) and Google Firebase (to deliver push
            notifications).
          </li>
          <li>
            <strong>Banks</strong> — the details needed to transfer your
            payout.
          </li>
          <li>
            <strong>Authorities</strong> — where the law of the Maldives
            requires it.
          </li>
        </ul>
      </LegalSection>

      <LegalSection heading="How long we keep it">
        <p>
          Your account details are kept while your account is active.
          Transaction and payout records are financial records shared with
          the stores that funded your cashback, so after account deletion
          they are retained in a form no longer linked to your identity — see
          our <a className="underline hover:text-foreground" href="/account-deletion">account deletion page</a> for
          exactly what is removed and what is anonymised.
        </p>
      </LegalSection>

      <LegalSection heading="Your choices">
        <ul>
          <li>View and correct your profile and payout details in the app.</li>
          <li>Turn push notifications off in your device settings.</li>
          <li>
            Delete your account — the process is described on the account
            deletion page.
          </li>
        </ul>
      </LegalSection>

      <LegalSection heading="Changes">
        <p>
          If this policy changes in a way that matters, we will tell you in
          the app or by notification before the change takes effect. The
          date at the top is the version you are reading.
        </p>
      </LegalSection>
    </LegalPage>
  );
}
