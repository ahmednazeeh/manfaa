'use client';

import { useRef, useState } from 'react';
import Link from 'next/link';
import { HandCoins, Landmark } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useOutstanding, useProductCategories } from '@/lib/queries';
import { can } from '@/lib/roles';
import { Button } from '@/components/ui/button';
import {
  Card,
  CardContent,
  CardHeader,
  CardHeading,
  CardTitle,
} from '@/components/ui/card';
import { useLayout } from '@/components/app-layout/context';
import { CreditCustomerDialog } from '@/components/credit/credit-customer-dialog';
import {
  CreditResultCard,
  type CreditResult,
} from '@/components/credit/credit-customer-form';

/**
 * QUICK ACTIONS (owner, 2026-08-25): the two things a till reaches for,
 * on the screen it already has open. Crediting a customer happens HERE, in
 * a dialog over the dashboard, because a cashier with a customer at the
 * counter should not have to leave the figures to record a sale; settling
 * goes to the wizard it has always gone to, because paying is a
 * several-step decision that deserves a screen.
 *
 * Each action is gated on the permission its DESTINATION already demands —
 * `credits.create` for the credit form (`merchant.can:credits.create` on
 * POST /merchant/credits), `settlements.create` for the settle wizard (the
 * RoleGate on /settlements/new) — so this card offers nothing an account
 * would be refused for pressing. Holding neither means the card is not
 * there at all: a reader who can only look at the ageing is not shown two
 * doors that are both locked.
 */
export function QuickActionsCard() {
  const { t } = useTranslation();
  const { me } = useLayout();
  const canCredit = can(me, 'credits.create');
  const canSettle = can(me, 'settlements.create');
  // Already fetched by the dashboard itself — same key, one request.
  const outstanding = useOutstanding();
  const [creditOpen, setCreditOpen] = useState(false);
  const [result, setResult] = useState<CreditResult | null>(null);
  // The dialog is opened by state, not by a DialogTrigger, so it has no
  // trigger to hand focus back to on close. This is that trigger.
  const creditButtonRef = useRef<HTMLButtonElement>(null);

  if (!canCredit && !canSettle) {
    return null;
  }

  // Mirrors the toolbar's Settle now exactly: there is nothing to settle
  // when the board is clear, and two controls with the same name on one
  // screen must not disagree about that. Unknown (still loading, or the
  // endpoint failed) is not zero, so the action stays live.
  const nothingOutstanding = outstanding.data?.total.count === 0;

  return (
    <>
      <Card>
        <CardHeader>
          <CardHeading>
            <CardTitle>{t('dashboard.quickActionsTitle')}</CardTitle>
          </CardHeading>
        </CardHeader>
        <CardContent className="grid grid-cols-1 gap-5 sm:grid-cols-2">
          {canCredit && (
            <div className="flex flex-col gap-2">
              <Button
                ref={creditButtonRef}
                size="lg"
                onClick={() => setCreditOpen(true)}
              >
                <HandCoins />
                {t('dashboard.creditCustomer')}
              </Button>
              <span className="text-xs text-muted-foreground">
                {t('dashboard.creditCustomerHint')}
              </span>
            </div>
          )}
          {canSettle && (
            <div className="flex flex-col gap-2">
              {/* A dimmed LINK is still a link: `disabled` on an anchor is
                  not a thing, so it would stay tabbable and openable with
                  Enter while reading "Nothing to settle right now." With
                  nothing outstanding this is a real disabled button, which
                  the keyboard and assistive tech both believe. */}
              {nothingOutstanding ? (
                <Button size="lg" variant="outline" disabled>
                  <Landmark />
                  {t('dashboard.settleNow')}
                </Button>
              ) : (
                <Button size="lg" variant="outline" asChild>
                  <Link href="/settlements/new">
                    <Landmark />
                    {t('dashboard.settleNow')}
                  </Link>
                </Button>
              )}
              <span className="text-xs text-muted-foreground">
                {nothingOutstanding
                  ? t('dashboard.settleNothingDue')
                  : t('dashboard.settleNowHint')}
              </span>
            </div>
          )}
        </CardContent>
      </Card>

      {/* The dialog closes on success, so the confirmation has to land
          somewhere — and it is the /credit route's own card, not a second
          telling of it. Its "Credit another customer" means the same thing
          it means on the route: clear this and let me key the next one,
          which here is the empty form back on top. */}
      {result && (
        <CreditResultPanel
          result={result}
          onReset={() => {
            setResult(null);
            setCreditOpen(true);
          }}
          // …and a way to put the confirmation away that is NOT keying
          // another sale, since here "Credit another customer" opens the
          // dialog rather than revealing a form already on screen.
          onDismiss={() => setResult(null)}
        />
      )}

      {canCredit && (
        <CreditCustomerDialog
          open={creditOpen}
          onOpenChange={setCreditOpen}
          onCredited={setResult}
          returnFocusTo={creditButtonRef}
        />
      )}
    </>
  );
}

/**
 * The result card and the one query it needs for Dhivehi category names,
 * mounted only once there IS a result — the dashboard does not fetch a
 * merchant's product categories on the chance that somebody credits
 * someone.
 */
function CreditResultPanel({
  result,
  onReset,
  onDismiss,
}: {
  result: CreditResult;
  onReset: () => void;
  onDismiss: () => void;
}) {
  const categories = useProductCategories();

  return (
    <CreditResultCard
      result={result}
      categories={categories.data ?? []}
      onReset={onReset}
      onDismiss={onDismiss}
      // The dashboard column already spaces its children.
      className=""
    />
  );
}
