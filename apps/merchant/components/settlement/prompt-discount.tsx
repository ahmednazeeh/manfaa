'use client';

import {
  type SettlementPickerRow,
  type SettlementPreviewDiscount,
} from '@manfaa/api-client';
import { MoneyText } from '@manfaa/ui';
import { BadgePercent, Clock } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { addDaysToInstant, formatBusinessDate } from '@/lib/dates';
import { formatBp } from '@/lib/estimate';
import { promptDiscountReasonLabel } from '@/lib/labels';
import {
  Alert,
  AlertContent,
  AlertDescription,
  AlertIcon,
  AlertTitle,
} from '@/components/ui/alert';
import { Button } from '@/components/ui/button';

/**
 * How the PLAN §1 prompt-payment discount is SHOWN to a merchant.
 *
 * Two rules govern everything here:
 *
 *   1. **Never imply a discount the server did not grant.** Every figure on
 *      screen is one the API computed — `discount_laari` off a priced
 *      selection, never a percentage this panel applied to anything. The
 *      amount the merchant transfers is always the preview's
 *      `amount_due_laari`.
 *   2. **A refusal is an answer.** The API returns `reason_code` on refusals
 *      too, so "no discount" is shown as a sentence with an action behind it
 *      ("settle everything outstanding", "one of these is too old"), never as
 *      an absence the merchant has to notice.
 *
 * The verdict shown is ADVISORY until submit: the server re-decides it inside
 * the submit transaction, against the locked rows, and a clock ticking past
 * midnight legitimately withdraws it. The copy says so rather than pretending
 * the quote is a promise.
 */

/** "5%" — the platform's configured rate, formatted from integer bp. */
export function discountRateLabel(rateBp: number): string {
  return formatBp(rateBp);
}

/**
 * The discount as a summary line: "Prompt payment discount (5%) −MVR 0.38".
 * The amount is negated for display only — the integer is the server's.
 */
export function PromptDiscountRow({
  rateBp,
  discountLaari,
}: {
  rateBp: number | null;
  discountLaari: number;
}) {
  const { t } = useTranslation();

  return (
    <div className="flex justify-between gap-3">
      <span className="flex items-center gap-1.5 text-primary">
        <BadgePercent className="size-3.5 shrink-0" aria-hidden />
        {rateBp === null
          ? t('settlement.discountLineNoRate')
          : t('settlement.discountLine', { rate: discountRateLabel(rateBp) })}
      </span>
      <MoneyText laari={-discountLaari} className="text-primary" />
    </div>
  );
}

/**
 * The platform fee with its discount applied: the full fee struck through,
 * the discounted fee beside it. The subtraction is exact integer laari on two
 * figures the server produced (`fee_total_laari` and the discount's own fee
 * leg) — the panel never applies a rate to anything.
 */
export function DiscountedFeeAmount({
  feeLaari,
  feeDiscountLaari,
}: {
  feeLaari: number;
  feeDiscountLaari: number;
}) {
  if (feeDiscountLaari <= 0) {
    return <MoneyText laari={feeLaari} />;
  }

  return (
    <span className="flex items-center gap-2">
      <MoneyText
        laari={feeLaari}
        className="text-muted-foreground line-through"
      />
      <MoneyText laari={feeLaari - feeDiscountLaari} />
    </span>
  );
}

/**
 * The verdict, in a sentence.
 *
 * `variant` says what the `discount` block describes, and the two are never
 * mixed up:
 *
 *   - `priced` — the server evaluated it for exactly the selection on screen,
 *     so a grant can be shown as money.
 *   - `whole` — it was evaluated for settling EVERYTHING outstanding while a
 *     SMALLER selection is on screen. A grant here is not this batch's: it is
 *     what the merchant would earn by taking the rest too, which is what the
 *     nudge offers.
 */
export function PromptDiscountNotice({
  discount,
  variant,
  onSettleEverything,
}: {
  discount: SettlementPreviewDiscount;
  variant: 'priced' | 'whole';
  onSettleEverything?: () => void;
}) {
  const { t } = useTranslation();

  // Switched off platform-wide (0bp): there is no incentive to explain, and
  // inventing one would be the exact failure this component guards against.
  if (discount.rate_bp === 0 || discount.reason_code === 'disabled') {
    return null;
  }

  // Granted, but worth nothing: §7 credit memos had already covered the fee,
  // so the relief was capped to what was left owing. There is no saving to
  // announce and "−MVR 0.00" is not news.
  if (discount.eligible && discount.discount_laari === 0) {
    return null;
  }

  const rate = discountRateLabel(discount.rate_bp);
  const days = discount.max_age_days;

  if (discount.eligible && variant === 'priced') {
    return (
      <Alert variant="success" appearance="light">
        <AlertIcon>
          <BadgePercent />
        </AlertIcon>
        <AlertContent>
          <AlertTitle>
            <span className="flex flex-wrap items-center gap-1.5">
              {t('settlement.discountLine', { rate })}
              <MoneyText laari={-discount.discount_laari} />
            </span>
          </AlertTitle>
          <AlertDescription>
            {t('settlement.discountGrantedHint', { days })}
          </AlertDescription>
        </AlertContent>
      </Alert>
    );
  }

  if (discount.eligible) {
    // The grant belongs to the whole board, not to what is selected.
    return (
      <Alert variant="info" appearance="light">
        <AlertIcon>
          <BadgePercent />
        </AlertIcon>
        <AlertContent>
          <AlertTitle>{t('settlement.discountNudge', { rate })}</AlertTitle>
          <AlertDescription>
            <span className="flex flex-wrap items-center gap-1.5">
              {t('settlement.discountNudgeSaving')}
              <MoneyText laari={discount.discount_laari} />
            </span>
          </AlertDescription>
          {onSettleEverything !== undefined && (
            <div className="mt-2.5">
              <Button size="sm" variant="outline" onClick={onSettleEverything}>
                {t('settlement.settleAll')}
              </Button>
            </div>
          )}
        </AlertContent>
      </Alert>
    );
  }

  return (
    <Alert variant="secondary" appearance="light">
      <AlertIcon>
        <Clock />
      </AlertIcon>
      <AlertContent>
        <AlertTitle>
          {promptDiscountReasonLabel(t, discount.reason_code, { rate, days })}
        </AlertTitle>
        {/*
          Only offered against a PRICED refusal — there, "you left something
          behind" is a fact about the selection on screen and taking the rest
          is the fix. On the whole-board verdict the missing money is frozen
          on another unpaid batch, and no selection here can reach it.
        */}
        {variant === 'priced' &&
          discount.reason_code === 'not_all_outstanding' &&
          onSettleEverything !== undefined && (
            <div className="mt-2.5">
              <Button size="sm" variant="outline" onClick={onSettleEverything}>
                {t('settlement.settleAll')}
              </Button>
            </div>
          )}
      </AlertContent>
    </Alert>
  );
}

/**
 * The dashboard's standing hint: the DAY the merchant's oldest payable sale
 * stops earning the discount, so "settle promptly" is a date rather than an
 * exhortation.
 *
 * The date is the one piece of arithmetic the panel does on a rule — the
 * oldest line's `clock_start_at` plus the window the API reported, printed in
 * the business timezone (see lib/dates.ts). Whether a line still qualifies
 * remains entirely the server's answer: this only says when today's answer
 * changes, and it says nothing at all once the answer is already no.
 */
export function PromptDiscountDeadline({
  discount,
  rows,
}: {
  discount: SettlementPreviewDiscount;
  rows: SettlementPickerRow[];
}) {
  const { t } = useTranslation();

  if (discount.rate_bp === 0 || discount.reason_code === 'disabled') {
    return null;
  }

  const rate = discountRateLabel(discount.rate_bp);
  const days = discount.max_age_days;

  if (!discount.eligible) {
    return (
      <Alert variant="secondary" appearance="light">
        <AlertIcon>
          <Clock />
        </AlertIcon>
        <AlertContent>
          <AlertTitle>
            {promptDiscountReasonLabel(t, discount.reason_code, { rate, days })}
          </AlertTitle>
        </AlertContent>
      </Alert>
    );
  }

  // Nothing to earn (credits already cover the fee), or no line whose clock
  // has actually started — a payable row with a null clock start is a defect
  // in its own right (§13b), and this is not the screen that invents a date
  // for one.
  const started = rows.filter(
    (row): row is SettlementPickerRow & { clock_start_at: string } =>
      row.clock_start_at !== null,
  );
  if (discount.discount_laari === 0 || started.length === 0) {
    return null;
  }

  // The earliest clock start is the line that ages out of the window first.
  // Compared as instants, not as strings, so a differently-offset timestamp
  // could never sort ahead of an earlier one.
  const oldest = started.reduce((earliest, row) =>
    Date.parse(row.clock_start_at) < Date.parse(earliest.clock_start_at)
      ? row
      : earliest,
  );
  const stopsQualifying = formatBusinessDate(
    addDaysToInstant(oldest.clock_start_at, days),
  );

  return (
    <Alert variant="info" appearance="light">
      <AlertIcon>
        <BadgePercent />
      </AlertIcon>
      <AlertContent>
        <AlertTitle>
          {t('settlement.discountDeadlineTitle', {
            rate,
            date: stopsQualifying,
          })}
        </AlertTitle>
        <AlertDescription>
          <span className="flex flex-wrap items-center gap-1.5">
            {t('settlement.discountDeadlineBody')}
            <MoneyText laari={discount.discount_laari} />
          </span>
        </AlertDescription>
      </AlertContent>
    </Alert>
  );
}
