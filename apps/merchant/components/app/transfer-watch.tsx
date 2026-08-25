'use client';

import { useEffect, useState, type ElementType, type ReactNode } from 'react';
import {
  isTransferWatched,
  type TransferProgress,
  type TransferWatchReason,
} from '@manfaa/api-client';
import { MoneyText, useFormatMoney } from '@manfaa/ui';
import {
  BadgeCheck,
  Bell,
  FileX2,
  Hourglass,
  Landmark,
  ShieldQuestion,
  Wallet as WalletIcon,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';
import {
  transferPollStopped,
  useTransferProgress,
  type TransferProgressTarget,
} from '@/lib/queries';
import { cn } from '@/lib/utils';
import { Progress } from '@/components/ui/progress';

/**
 * What happened to the bank transfer the merchant just evidenced — a
 * settlement receipt or a wallet top-up slip — shown live on the screen they
 * are already looking at (owner, 2026-08-25).
 *
 * ONE component serves both flows because the server answers both with one
 * payload (`TransferProgress`): the settlement wizard's success step and the
 * wallet top-up dialog's success state differ only in which id they name and
 * which words the outcome earns.
 *
 * THE HONESTY RULE. A progress bar here is a claim that somebody is looking
 * at the bank right now, and that claim is the SERVER's to make, never this
 * component's. `watching` is a field on the payload — true only when
 * auto-verification is on, the account the merchant paid into is one Manfaa
 * can actually read, and the watch window is still open. Nothing here infers
 * it, and when it is false there is no bar, no spinner and no ticking clock:
 * just the plain truth that a person will confirm the transfer shortly,
 * worded from the reason the API gave.
 *
 * Two things this component adds on top of `watching`, and both can only ever
 * turn the bar OFF: a LOCAL EXPIRY check — past `watch_until` the window is
 * over whether or not the last read said so, so the bar stops there rather
 * than sitting at 100% pretending — and a STOPPED-POLL check: once the reads
 * have given up, the payload in hand is a memory rather than an observation,
 * and a bar over it would be this client's own invention.
 *
 * THE SCREEN IS A WINDOW, NOT A TRIGGER. The poll drives nothing: the server
 * watches the bank whether or not this is open, and the push + SMS on a match
 * fire either way. Closing the page costs the merchant nothing, and the copy
 * says so.
 *
 * CLOCKS. Every instant is measured on the SERVER's clock. `checked_at` is
 * pinned to the local moment the response arrived (`dataUpdatedAt`), and the
 * window's start and end are placed relative to that — so a till laptop whose
 * clock is ten minutes out still sees an honest countdown.
 */

/** The visual register of an answer — bubble tint and icon colour. */
type Tone = 'live' | 'good' | 'partial' | 'bad' | 'quiet';

const TONE_BUBBLE: Record<Tone, string> = {
  live: 'bg-primary/10',
  good: 'bg-green-500/15',
  partial: 'bg-yellow-500/15',
  bad: 'bg-destructive/10',
  quiet: 'bg-muted',
};

const TONE_ICON: Record<Tone, string> = {
  live: 'text-primary',
  good: 'text-green-600 dark:text-green-500',
  partial: 'text-yellow-600 dark:text-yellow-500',
  bad: 'text-destructive',
  quiet: 'text-muted-foreground',
};

/**
 * Why nothing is being watched, in the merchant's own terms. Every one of
 * these lands on the same promise — a person confirms it, nothing is needed
 * from you — because that is what is actually true in each case; only the
 * first sentence differs, and `terminal` never reaches here (a decided
 * transfer has an outcome to show instead).
 */
const MANUAL_BODY_KEYS: Record<TransferWatchReason, string> = {
  auto_verify_off: 'transferWatch.manualBodyAutoVerifyOff',
  no_verify_profile: 'transferWatch.manualBodyNoVerifyProfile',
  // No automatic check ever started on this one, so it must NOT borrow the
  // expired wording ("the automatic check ran and did not find it").
  never_watched: 'transferWatch.manualBody',
  window_expired: 'transferWatch.manualBodyWindowExpired',
  terminal: 'transferWatch.manualBody',
  unknown: 'transferWatch.manualBody',
};

interface WatchClock {
  /** The whole window, ms — `watch_until − watch_started_at`. */
  totalMs: number;
  elapsedMs: number;
  remainingMs: number;
  /** 0–100, for the determinate bar. */
  percent: number;
}

/**
 * The watch window mapped onto the local clock, or null when the payload
 * does not describe a window (no start, no end, or an unparseable stamp) —
 * in which case there is nothing honest to draw and the bar is left out.
 *
 * `anchor` is the local instant the response landed, which stands for
 * `checked_at`; every other instant is placed as an offset from it, so only
 * ELAPSED local time is ever added to a server time.
 */
function watchClock(
  progress: TransferProgress,
  anchor: number,
  now: number,
): WatchClock | null {
  const checked = Date.parse(progress.checked_at);
  const started =
    progress.watch_started_at === null
      ? Number.NaN
      : Date.parse(progress.watch_started_at);
  const until =
    progress.watch_until === null
      ? Number.NaN
      : Date.parse(progress.watch_until);

  if (Number.isNaN(checked) || Number.isNaN(started) || Number.isNaN(until)) {
    return null;
  }

  const totalMs = until - started;
  if (totalMs <= 0) return null;

  const localStart = anchor - (checked - started);
  const elapsedMs = Math.min(Math.max(now - localStart, 0), totalMs);

  return {
    totalMs,
    elapsedMs,
    remainingMs: totalMs - elapsedMs,
    percent: (elapsedMs / totalMs) * 100,
  };
}

/**
 * m:ss, wrapped in a directional isolate so the digits keep their order when
 * the sentence around them is Thaana — the same protection formatMoney gives
 * an amount.
 */
function clockLabel(ms: number): string {
  const seconds = Math.max(0, Math.round(ms / 1000));
  const minutes = Math.floor(seconds / 60);
  return `⁦${minutes}:${String(seconds % 60).padStart(2, '0')}⁩`;
}

function Detail({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5">
      <dt className="text-xs font-medium uppercase text-muted-foreground">
        {label}
      </dt>
      <dd className="text-sm font-semibold text-mono">{children}</dd>
    </div>
  );
}

function Details({ children }: { children: ReactNode }) {
  return (
    <dl className="grid w-full grid-cols-1 gap-x-6 gap-y-3 rounded-lg border border-border bg-muted/40 p-4 sm:grid-cols-2">
      {children}
    </dl>
  );
}

export function TransferWatch({
  target,
  titleAs: Title = 'h2',
  reference = null,
  bankName = null,
  className,
}: {
  /** Which row to read — a settlement id, or a top-up claim id. */
  target: TransferProgressTarget;
  /**
   * The element that carries the heading. Defaults to a plain h2; inside a
   * dialog pass DialogTitle so the live heading is also the accessible name
   * of the dialog rather than a second, static one.
   */
  titleAs?: ElementType;
  /** The batch's reference, known to the wizard before any read lands. */
  reference?: string | null;
  /** Where the merchant said they sent it, for the top-up flow. */
  bankName?: string | null;
  className?: string;
}) {
  const { t } = useTranslation();
  const formatMoney = useFormatMoney();
  const query = useTransferProgress(target);
  const progress = query.data;

  const [now, setNow] = useState(() => Date.now());

  const clock =
    progress === undefined
      ? null
      : watchClock(progress, query.dataUpdatedAt, now);

  // Has this client stopped asking? A live bar is a claim that somebody is
  // looking at the bank right now, and it is only ever as good as the read
  // behind it: once the poll has given up (a final 401/403/404, or a run of
  // failures) the last payload is a memory, not an observation, and
  // the card must fall back to the quiet promise rather than animate over a
  // connection it is no longer using.
  const stalled = transferPollStopped(
    query.status,
    query.error,
    query.failureCount,
  );

  // The server's word, then this client's own two checks: it must still be
  // asking, and the window must not have run out locally — past
  // `watch_until` the window is over even if the last read said it was open.
  const watching =
    progress !== undefined &&
    isTransferWatched(progress) &&
    !stalled &&
    (clock === null || clock.remainingMs > 0);

  // The second hand runs only while there is a real window to run it against,
  // and stops the moment that window closes — no clock ticks over a screen
  // where nothing is being watched.
  const ticking = watching && clock !== null;

  useEffect(() => {
    if (!ticking) return;
    const id = window.setInterval(() => setNow(Date.now()), 1000);
    return () => window.clearInterval(id);
  }, [ticking]);

  let tone: Tone = 'quiet';
  let icon: ReactNode = <Hourglass />;
  let title = t('transferWatch.loadingTitle');
  let body: string = t('transferWatch.loadingBody');
  let details: ReactNode = null;
  let rejectedReason: string | null | undefined;

  // Decided. The outcome is read off the payload the two flows share, but
  // the WORDS are each flow's own: a settled batch and a credited wallet are
  // not the same news, and neither is a remainder still owed.
  if (
    progress !== undefined &&
    progress.kind === 'settlement_payment' &&
    progress.outcome !== null
  ) {
    const outcome = progress.outcome;
    if (outcome.result === 'settled') {
      tone = 'good';
      icon = <BadgeCheck />;
      title = t('transferWatch.settledTitle', { reference: outcome.reference });
      body = t('transferWatch.settledBody');
      details = (
        <Details>
          <Detail label={t('transferWatch.labelReference')}>
            <span dir="ltr">{outcome.reference}</span>
          </Detail>
          <Detail label={t('transferWatch.labelReceived')}>
            <MoneyText laari={outcome.amount_received_laari} />
          </Detail>
        </Details>
      );
    } else if (outcome.result === 'partially_settled') {
      tone = 'partial';
      icon = <ShieldQuestion />;
      title = t('transferWatch.partialTitle', { reference: outcome.reference });
      body = t('transferWatch.partialBody');
      details = (
        <Details>
          <Detail label={t('transferWatch.labelReceived')}>
            <MoneyText laari={outcome.amount_received_laari} />
          </Detail>
          <Detail label={t('transferWatch.labelOutstanding')}>
            <MoneyText
              laari={outcome.amount_outstanding_laari}
              className="text-yellow-600 dark:text-yellow-500"
            />
          </Detail>
        </Details>
      );
    } else if (outcome.result === 'rejected') {
      tone = 'bad';
      icon = <FileX2 />;
      title = t('transferWatch.settlementRejectedTitle');
      body = t('transferWatch.settlementRejectedBody', {
        reference: outcome.reference,
      });
      rejectedReason = outcome.rejected_reason;
    } else {
      // A result this build has not heard of. It is decided — that much is
      // certain — so say only that, and send them where the truth lives.
      title = t('transferWatch.decidedTitle');
      body = t('transferWatch.decidedBody');
    }
  } else if (
    progress !== undefined &&
    progress.kind === 'wallet_top_up' &&
    progress.outcome !== null
  ) {
    const outcome = progress.outcome;
    if (outcome.result === 'credited') {
      tone = 'good';
      icon = <WalletIcon />;
      title = t('transferWatch.creditedTitle', {
        amount: formatMoney(outcome.credited_laari),
      });
      // The balance is the server's AT READ TIME, not a snapshot from the
      // instant of the credit: if the hourly auto-settle spent it in
      // between, "balance now" still has to be true.
      body = t('transferWatch.creditedBody', {
        balance: formatMoney(outcome.balance_laari),
      });
      details = (
        <Details>
          <Detail label={t('transferWatch.labelAdded')}>
            <MoneyText laari={outcome.credited_laari} />
          </Detail>
          <Detail label={t('transferWatch.labelBalance')}>
            <MoneyText laari={outcome.balance_laari} />
          </Detail>
        </Details>
      );
    } else if (outcome.result === 'rejected') {
      tone = 'bad';
      icon = <FileX2 />;
      title = t('transferWatch.topUpRejectedTitle');
      body = t('transferWatch.topUpRejectedBody');
      rejectedReason = outcome.rejected_reason;
    } else {
      title = t('transferWatch.decidedTitle');
      body = t('transferWatch.decidedBody');
    }
  } else if (watching && progress !== undefined) {
    tone = 'live';
    icon = <Landmark />;
    title = t('transferWatch.checkingTitle');
    body =
      progress.kind === 'wallet_top_up'
        ? t('transferWatch.checkingBodyTopUp', {
            amount: formatMoney(progress.amount_laari),
          })
        : t('transferWatch.checkingBodySettlement');
    details = (
      <Details>
        {progress.kind === 'settlement_payment' && reference !== null ? (
          <Detail label={t('transferWatch.labelReference')}>
            <span dir="ltr">{reference}</span>
          </Detail>
        ) : null}
        <Detail label={t('transferWatch.labelAmount')}>
          <MoneyText laari={progress.amount_laari} />
        </Detail>
        {progress.kind === 'wallet_top_up' && bankName !== null ? (
          <Detail label={t('transferWatch.labelBank')}>{bankName}</Detail>
        ) : null}
      </Details>
    );
  } else if (progress !== undefined || query.isError) {
    // Nothing is being watched. No bar, no clock — the plain promise, worded
    // from the reason the server gave. A read that failed (a 403 from a
    // missing `settlements.view` / `wallet.view`, or a dropped connection)
    // lands here too: it changes nothing about what the server is doing, so
    // the safe, true sentence is the same one.
    const reason: TransferWatchReason =
      progress?.reason ??
      (progress !== undefined && isTransferWatched(progress) && !stalled
        ? // The payload still said watching and the window ran out under it.
          'window_expired'
        : // Either nothing is known, or we simply stopped asking — and "the
          // automatic check ran and did not find your transfer" is not
          // something this client may say about reads it never got.
          'unknown');
    tone = 'quiet';
    icon = <Hourglass />;
    title = t('transferWatch.manualTitle');
    body = t(MANUAL_BODY_KEYS[reason]);
  }

  return (
    <div className={cn('flex w-full flex-col gap-4', className)}>
      <div className="flex items-start gap-3">
        <span
          className={cn(
            'flex size-10 shrink-0 items-center justify-center rounded-full',
            TONE_BUBBLE[tone],
          )}
        >
          <span className={cn('[&>svg]:size-5', TONE_ICON[tone])}>{icon}</span>
        </span>
        {/* pe-6 keeps the heading clear of the dialog's own close button,
            which floats over the top-right corner of the content box. */}
        <div className="flex min-w-0 flex-col gap-1 pe-6" aria-live="polite">
          <Title className="text-lg font-semibold">{title}</Title>
          <p className="text-sm text-secondary-foreground">{body}</p>
        </div>
      </div>

      {watching && (
        <div className="flex w-full flex-col gap-2.5 rounded-lg border border-border bg-muted/40 p-4">
          <div className="flex items-center gap-2 text-xs font-medium">
            <span className="relative flex size-2 shrink-0">
              <span className="absolute inline-flex size-full animate-ping rounded-full bg-primary/60 motion-reduce:hidden" />
              <span className="relative inline-flex size-2 rounded-full bg-primary" />
            </span>
            {t('transferWatch.liveLabel')}
          </div>
          {clock !== null && (
            <>
              <Progress
                value={clock.percent}
                aria-label={t('transferWatch.liveLabel')}
              />
              <div className="flex items-center justify-between gap-3 text-xs tabular-nums text-muted-foreground">
                <span>
                  {t('transferWatch.elapsed', {
                    time: clockLabel(clock.elapsedMs),
                  })}
                </span>
                <span>
                  {t('transferWatch.remaining', {
                    time: clockLabel(clock.remainingMs),
                  })}
                </span>
              </div>
            </>
          )}
          <p className="flex items-start gap-1.5 text-xs text-muted-foreground">
            <Bell className="mt-0.5 size-3.5 shrink-0" />
            {t('transferWatch.closeSafe')}
          </p>
        </div>
      )}

      {details}

      {rejectedReason !== undefined && (
        <div className="flex w-full flex-col gap-1 rounded-lg border border-border bg-muted/40 p-4">
          <span className="text-xs font-medium uppercase text-muted-foreground">
            {t('transferWatch.reason')}
          </span>
          <span className="text-sm text-mono">
            {rejectedReason ?? t('transferWatch.noReason')}
          </span>
        </div>
      )}
    </div>
  );
}
