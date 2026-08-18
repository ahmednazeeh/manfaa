'use client';

import { type ReactNode } from 'react';
import type { MerchantChangeRequest } from '@manfaa/api-client';
import { ArrowRight, Clock3, ImageOff } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { formatBusinessDateTime } from '@/lib/dates';
import {
  changeFieldLabel,
  changeKindLabel,
  changeKindNote,
} from '@/lib/labels';
import { changeRows, type Pin } from '@/lib/pending-changes';
import {
  Alert,
  AlertContent,
  AlertDescription,
  AlertIcon,
  AlertTitle,
} from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';

/**
 * MR9's merchant-side answer to "did my edit go live?" — no, not yet.
 *
 * A live store's public claims (its name, category, channel, logo, website,
 * its "what earns cashback" promise, and its branch estate) queue for admin
 * review instead of applying, so the panel must never let a save LOOK like it
 * landed. Every gated write answers 202 and this is what the screen shows
 * afterwards: what is waiting, what it would replace, and when it was sent.
 *
 * The form beside it keeps showing the LIVE values on purpose — those are
 * what a shopper reads until an admin approves — which makes this banner the
 * only place the proposed values exist on screen. Hence the full diff rather
 * than a "changes pending" chip.
 *
 * Re-saving is allowed and says so: the server supersedes the pending request
 * with the newer one (carrying forward keys the new submission did not
 * mention), so nobody is ever stuck behind their own earlier edit.
 */

/** Renders one proposed/current value; falls back to plain text. */
export type ChangeValueFormatter = (
  field: string,
  value: unknown,
) => ReactNode | undefined;

function LogoValue({ url, alt }: { url: unknown; alt: string }) {
  if (typeof url !== 'string' || url === '') {
    return (
      <span className="inline-flex size-10 items-center justify-center rounded-md border border-border bg-muted/40 text-muted-foreground">
        <ImageOff className="size-4" />
      </span>
    );
  }

  return (
    <img
      src={url}
      alt={alt}
      className="size-10 rounded-md border border-border bg-background object-contain"
    />
  );
}

function ChangeValue({
  field,
  value,
  formatValue,
  muted = false,
}: {
  field: string;
  value: unknown;
  formatValue?: ChangeValueFormatter;
  muted?: boolean;
}) {
  const { t } = useTranslation();

  if (field === 'logo') {
    return (
      <LogoValue
        url={value}
        alt={muted ? t('pending.logoCurrentAlt') : t('pending.logoProposedAlt')}
      />
    );
  }

  if (field === 'location') {
    const pin = value as Pin | null;
    return (
      <span
        dir="ltr"
        className={
          muted
            ? 'tabular-nums text-muted-foreground'
            : 'tabular-nums font-medium'
        }
      >
        {pin === null
          ? t('pending.noPin')
          : `${pin.lat.toFixed(5)}, ${pin.lng.toFixed(5)}`}
      </span>
    );
  }

  const formatted = formatValue?.(field, value);

  if (formatted !== undefined && formatted !== null) {
    return (
      <span className={muted ? 'text-muted-foreground' : 'font-medium'}>
        {formatted}
      </span>
    );
  }

  if (value === null || value === undefined || value === '') {
    return <span className="text-muted-foreground">{t('pending.notSet')}</span>;
  }

  return (
    <span
      className={
        muted ? 'text-muted-foreground' : 'font-medium whitespace-pre-line'
      }
    >
      {String(value)}
    </span>
  );
}

/**
 * The pending-review banner for ONE queued change.
 *
 * The closing note says what is true while this waits and what a second save
 * does — per KIND, since a queued rename, a queued new branch and a queued
 * removal are three different promises. `formatValue` turns stored values
 * into the words the rest of the screen uses (a category slug into its
 * curated name, a channel into "In Store & Online"), because a diff that
 * prints `in_store` is a diff nobody reads.
 */
export function PendingChangeAlert({
  change,
  subject,
  note,
  formatValue,
  className,
}: {
  change: MerchantChangeRequest;
  /**
   * WHICH thing is waiting, when the kind alone does not say it — the branch
   * a queued update belongs to. A branch's snapshot holds only the fields in
   * play, so an edit that left the name alone has no name in it; the caller
   * has the branch list and resolves it.
   */
  subject?: string;
  note?: ReactNode;
  formatValue?: ChangeValueFormatter;
  className?: string;
}) {
  const { t } = useTranslation();
  const rows = changeRows(change);
  const closing = note ?? changeKindNote(t, change.kind);

  return (
    <Alert variant="info" appearance="light" size="lg" className={className}>
      <AlertIcon>
        <Clock3 />
      </AlertIcon>
      {/* min-w-0 so a long website or terms paragraph wraps inside the alert
          instead of stretching it past the card. */}
      <AlertContent className="min-w-0 flex-1">
        <AlertTitle>{t('pending.title')}</AlertTitle>
        <AlertDescription className="flex flex-col gap-3">
          {/* A <div>, not a <p>: AlertDescription puts a bottom margin on
              every paragraph inside it, and this stack owns its own gaps. */}
          <div className="text-xs">
            {changeKindLabel(t, change.kind)}
            {subject !== undefined && subject !== '' && (
              <>
                {' · '}
                <span className="font-medium">{subject}</span>
              </>
            )}
            {change.submitted_at !== null && (
              <>
                {' · '}
                {t('pending.submittedAt', {
                  when: formatBusinessDateTime(change.submitted_at),
                })}
              </>
            )}
          </div>

          {rows.length > 0 && (
            <dl className="grid grid-cols-[minmax(5rem,auto)_1fr] items-baseline gap-x-4 gap-y-2">
              {rows.map((row) => (
                <div key={row.field} className="contents">
                  <dt className="text-xs text-muted-foreground">
                    {changeFieldLabel(t, change.kind, row.field)}
                  </dt>
                  <dd className="flex flex-wrap items-center gap-2 break-words">
                    {'from' in row && (
                      <>
                        <ChangeValue
                          field={row.field}
                          value={row.from}
                          formatValue={formatValue}
                          muted
                        />
                        <ArrowRight className="size-3.5 shrink-0 text-muted-foreground rtl:rotate-180" />
                      </>
                    )}
                    <ChangeValue
                      field={row.field}
                      value={row.to}
                      formatValue={formatValue}
                    />
                  </dd>
                </div>
              ))}
            </dl>
          )}

          <div className="text-xs text-muted-foreground">{closing}</div>
        </AlertDescription>
      </AlertContent>
    </Alert>
  );
}

/**
 * The row-level marker on a branch that has something queued against it. The
 * row still shows the LIVE branch — a queued rename or removal has not moved
 * it — so without this the table and the banner above it look like they
 * disagree.
 */
export function PendingChangeBadge({
  kind,
}: {
  kind: MerchantChangeRequest['kind'];
}) {
  const { t } = useTranslation();

  return (
    <Badge variant="info" appearance="light" size="sm">
      <Clock3 className="size-3" />
      {kind === 'branch_delete'
        ? t('pending.badgeRemoval')
        : t('pending.badge')}
    </Badge>
  );
}
