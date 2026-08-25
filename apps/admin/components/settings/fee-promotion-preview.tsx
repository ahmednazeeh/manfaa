'use client';

import { type ReactNode } from 'react';
import { formatPercent, type FeePromotionKind } from '@manfaa/api-client';
import { Megaphone, Store } from 'lucide-react';
import { daysRemaining, FEE_PROMOTION_KIND_LABELS } from '@/lib/fee-promotions';
import { formatDate, formatDateTime } from '@/lib/format';
import { Badge } from '@/components/ui/badge';

/**
 * WHAT A MERCHANT WILL ACTUALLY SEE, built from the form as it stands.
 *
 * Two audiences, because the API has two endpoints and they deliberately say
 * different things:
 *
 *   the MERCHANT   /api/merchant/fee-promotion — the store's own banner on
 *                  the panel and in the till app. Carries the fee, the
 *                  sentence, and dates that belong to THAT store.
 *   a VISITOR      /api/public/fee-promotion — the merchant landing page.
 *                  The OFFER and nothing else: an introductory offer is
 *                  published as "X days at Y%", never as a date, because a
 *                  stranger has no approval stamp and any date printed would
 *                  be a promise about a merchant they are not yet.
 *
 * The words and figures here are exactly the fields those endpoints will
 * send. The STYLING is each surface's own — this is a proof of the copy, not
 * a mock of the panel.
 *
 * The Dhivehi line is rendered `dir="rtl" lang="dv"` in Thaana, so a
 * superadmin can see it read correctly before a merchant does.
 */

function Frame({
  icon,
  title,
  note,
  children,
}: {
  icon: ReactNode;
  title: string;
  note: string;
  children: ReactNode;
}) {
  return (
    <div className="flex flex-col gap-2.5 rounded-lg border border-border bg-muted/30 p-4">
      <div className="flex items-center gap-2">
        <span className="text-muted-foreground">{icon}</span>
        <span className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
          {title}
        </span>
      </div>
      {children}
      <p className="text-[0.6875rem] leading-snug text-muted-foreground/80">
        {note}
      </p>
    </div>
  );
}

/** The banner sentence, or an honest gap where it has not been written. */
function Sentence({
  value,
  language,
}: {
  value: string;
  language: 'en' | 'dv';
}) {
  if (value.trim() === '') {
    return (
      <p className="text-sm text-muted-foreground italic">
        {language === 'en'
          ? 'No English wording yet — the offer cannot be switched on without it.'
          : 'No Dhivehi wording yet — the offer cannot be switched on without it.'}
      </p>
    );
  }

  return language === 'dv' ? (
    <p dir="rtl" lang="dv" className="text-sm text-foreground">
      {value}
    </p>
  ) : (
    <p className="text-sm text-foreground">{value}</p>
  );
}

function Fee({ percent }: { percent: string | null }) {
  if (percent === null) {
    return <span className="text-muted-foreground italic">fee not set</span>;
  }
  return <span className="font-semibold">{formatPercent(percent)}</span>;
}

export function FeePromotionPreview({
  kind,
  feePercent,
  introDays,
  endsAtMs,
  bannerEn,
  bannerDv,
  nowMs,
}: {
  kind: FeePromotionKind;
  /** A canonical 2-decimal percent, or null while the fee is unset. */
  feePercent: string | null;
  /** Introductory only: the length of the offer. */
  introDays: number | null;
  /** The window end, exclusive; null when there is not a usable one yet. */
  endsAtMs: number | null;
  bannerEn: string;
  bannerDv: string;
  nowMs: number;
}) {
  const label = FEE_PROMOTION_KIND_LABELS[kind];
  const intro = kind === 'introductory';
  const left = endsAtMs === null ? null : daysRemaining(nowMs, endsAtMs);

  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <Frame
        icon={<Store className="size-4" />}
        title="Merchant panel and till app"
        note={
          intro
            ? 'Every store sees its OWN count, measured from the day it was approved — the dates above are for a store approved today. A store whose first days are already behind it sees no banner at all.'
            : 'Every store sees the same window: a platform-wide offer does not care how old a merchant is.'
        }
      >
        <div className="flex flex-wrap items-center gap-2">
          <Badge variant="success" appearance="light" size="sm">
            {label}
          </Badge>
          <span className="text-sm">
            Platform fee while it runs: <Fee percent={feePercent} />
          </span>
        </div>
        <p className="text-xs text-muted-foreground">
          {endsAtMs === null ? (
            'Last day — (the window is not complete yet)'
          ) : (
            <>
              Last day {formatDate(new Date(endsAtMs - 1).toISOString())} ·{' '}
              {left === 1 ? '1 day left' : `${left} days left`}
            </>
          )}
        </p>
        {/* The window end you SET is exclusive — the first instant the
            promotion stops pricing a sale. The merchant is shown the last day
            it still applies, one day earlier, because that is the day they
            plan against; this line is the boundary itself, so a superadmin
            typing it can see both. */}
        {endsAtMs !== null && (
          <p className="text-[0.6875rem] text-muted-foreground/80">
            Stops pricing sales at{' '}
            {formatDateTime(new Date(endsAtMs).toISOString())}.
          </p>
        )}
        <Sentence value={bannerEn} language="en" />
        <Sentence value={bannerDv} language="dv" />
      </Frame>

      <Frame
        icon={<Megaphone className="size-4" />}
        title="Merchant landing page (signed out)"
        note={
          intro
            ? 'No date is published for an introductory offer: a visitor has no approval stamp, so the landing page says how long the offer runs, never when anybody’s runs out.'
            : 'The end date IS published here — it is the platform’s own campaign deadline, and it belongs on the poster.'
        }
      >
        <div className="flex flex-wrap items-center gap-2">
          <Badge variant="info" appearance="light" size="sm">
            {label}
          </Badge>
          <span className="text-sm">
            {intro ? (
              <>
                <Fee percent={feePercent} /> platform fee for your first{' '}
                {introDays === null || introDays < 1 ? '—' : introDays} days
              </>
            ) : (
              <>
                <Fee percent={feePercent} /> platform fee
                {endsAtMs === null
                  ? ''
                  : ` — last day ${formatDate(new Date(endsAtMs - 1).toISOString())}`}
              </>
            )}
          </span>
        </div>
        <Sentence value={bannerEn} language="en" />
        <Sentence value={bannerDv} language="dv" />
      </Frame>
    </div>
  );
}
