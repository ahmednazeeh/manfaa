'use client';

import { ReactNode } from 'react';
import type {
  ChangeRequestDiff,
  ChangeRequestKind,
  MerchantChangeRequest,
  MerchantChannel,
} from '@manfaa/api-client';
import { ArrowDown, ArrowRight, ImageOff, MapPin } from 'lucide-react';
import { isBranchKind } from '@/lib/change-requests';
import { changeFieldLabel, merchantChannelLabel } from '@/lib/labels';
import { cn } from '@/lib/utils';
import { useCategoryNames } from '@/hooks/use-category-names';
import { Badge } from '@/components/ui/badge';

/**
 * The before -> after a reviewer decides on.
 *
 * Both halves come off the request itself, never off the live store: the
 * server snapshots the affected fields at SUBMIT time precisely so a
 * two-day-old request still shows the comparison the merchant proposed,
 * whatever has moved since (an instant contact edit, an admin correction, a
 * superseding submission).
 *
 * Three shapes, because three shapes are what the queue actually contains:
 * a field-by-field diff (profile edits and branch updates), a whole
 * proposed branch (a create has no "before"), and a whole existing branch
 * (a removal proposes no fields — the branch itself is the decision).
 */

const CHANNEL_VALUES: readonly string[] = ['in_store', 'online', 'both'];

function isChannel(value: unknown): value is MerchantChannel {
  return typeof value === 'string' && CHANNEL_VALUES.includes(value);
}

/** Null, undefined and '' are one thing to a reviewer: nothing is there. */
function isBlank(value: unknown): boolean {
  return value === null || value === undefined || value === '';
}

function Blank({ children = 'Not set' }: { children?: ReactNode }) {
  return <span className="text-muted-foreground italic">{children}</span>;
}

/**
 * One field's value in the words the console uses everywhere else: a channel
 * as "In Store & Online", a category slug as its curated English name, a
 * website as a link you can actually open to check the claim.
 */
function FieldValue({
  kind,
  field,
  value,
  categoryNames,
}: {
  kind: ChangeRequestKind;
  field: string;
  value: unknown;
  categoryNames: Map<string, string>;
}) {
  if (isBlank(value)) {
    return <Blank />;
  }

  if (field === 'channel' && isChannel(value)) {
    return <span>{merchantChannelLabel(value)}</span>;
  }

  if (field === 'category' && typeof value === 'string') {
    const name = categoryNames.get(value);
    return (
      <span>
        {name ?? value}
        {name === undefined ? (
          <span className="ms-1 text-xs text-muted-foreground">
            (unknown slug)
          </span>
        ) : null}
      </span>
    );
  }

  if (field === 'website_url' && typeof value === 'string') {
    return (
      <a
        href={value.startsWith('http') ? value : `https://${value}`}
        target="_blank"
        rel="noreferrer"
        className="break-all text-primary underline underline-offset-2"
      >
        {value}
      </a>
    );
  }

  if (typeof value === 'boolean') {
    return <span>{value ? 'Yes' : 'No'}</span>;
  }

  // The customer-facing promise is prose, sometimes several lines of it, and
  // is shown to shoppers verbatim — so it is reviewed verbatim.
  const isProse = field === 'eligibility_basis' || field === 'address';
  return (
    <span
      className={cn(
        'break-words',
        isProse ? 'whitespace-pre-wrap' : 'break-all',
        kind === 'profile' && field === 'name_dv' ? 'text-base' : undefined,
      )}
    >
      {String(value)}
    </span>
  );
}

/** The staged file vs the one still being served. */
function LogoPreview({ url, alt }: { url: unknown; alt: string }) {
  if (typeof url !== 'string' || url === '') {
    return (
      <span className="flex size-24 shrink-0 flex-col items-center justify-center gap-1 rounded-xl border border-dashed border-border bg-muted/40 text-xs text-muted-foreground">
        <ImageOff className="size-4" />
        No logo
      </span>
    );
  }

  return (
    <span className="flex size-24 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-border bg-background">
      {/* Plain <img>: the file is served by the API host behind an
          authorising route, which next/image cannot fetch with credentials. */}
      <img src={url} alt={alt} className="size-full object-contain" />
    </span>
  );
}

type Tone = 'added' | 'removed' | 'changed';

const TONE_BADGES: Record<
  Tone,
  { label: string; variant: 'success' | 'destructive' | 'info' }
> = {
  added: { label: 'Added', variant: 'success' },
  removed: { label: 'Cleared', variant: 'destructive' },
  changed: { label: 'Changed', variant: 'info' },
};

function toneOf(from: unknown, to: unknown): Tone {
  if (isBlank(from)) {
    return 'added';
  }
  if (isBlank(to)) {
    return 'removed';
  }
  return 'changed';
}

/**
 * The before/after pair. Neutral on the left, highlighted on the right: the
 * old value is not wrong, it is simply what a shopper reads until somebody
 * decides. Red and green are reserved for the Added/Cleared chip, which is
 * the only place a colour carries meaning here.
 */
function Side({
  caption,
  emphasis,
  children,
}: {
  caption: string;
  emphasis?: boolean;
  children: ReactNode;
}) {
  return (
    <div
      className={cn(
        'min-w-0 rounded-md border p-3',
        emphasis
          ? 'border-primary/30 bg-primary/5'
          : 'border-border bg-muted/40',
      )}
    >
      <div className="mb-1 text-[0.6875rem] font-medium tracking-wide text-muted-foreground uppercase">
        {caption}
      </div>
      <div
        className={cn(
          'text-sm',
          emphasis ? 'font-medium text-foreground' : 'text-muted-foreground',
        )}
      >
        {children}
      </div>
    </div>
  );
}

function DiffCard({
  label,
  tone,
  children,
}: {
  label: string;
  tone: Tone;
  children: ReactNode;
}) {
  const badge = TONE_BADGES[tone];
  return (
    <div className="overflow-hidden rounded-lg border border-border">
      <div className="flex items-center justify-between gap-2 border-b border-border bg-muted/40 px-3 py-2">
        <span className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
          {label}
        </span>
        <Badge variant={badge.variant} appearance="light" size="sm">
          {badge.label}
        </Badge>
      </div>
      {children}
    </div>
  );
}

function BeforeAfter({
  before,
  after,
}: {
  before: ReactNode;
  after: ReactNode;
}) {
  return (
    <div className="grid items-center gap-2 p-3 sm:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)]">
      <Side caption="Before">{before}</Side>
      <div className="flex items-center justify-center text-muted-foreground">
        <ArrowDown className="size-4 sm:hidden" />
        <ArrowRight className="hidden size-4 sm:block" />
      </div>
      <Side caption="After" emphasis>
        {after}
      </Side>
    </div>
  );
}

function DiffRow({
  request,
  entry,
  categoryNames,
}: {
  request: MerchantChangeRequest;
  entry: ChangeRequestDiff;
  categoryNames: Map<string, string>;
}) {
  const from = entry.from ?? null;
  const to = entry.to ?? null;
  const label = changeFieldLabel(request.kind, entry.field);
  const tone = toneOf(from, to);

  if (entry.field === 'logo') {
    return (
      <DiffCard label={label} tone={tone}>
        <BeforeAfter
          before={<LogoPreview url={from} alt={`${label} currently in use`} />}
          after={<LogoPreview url={to} alt={`${label} proposed`} />}
        />
      </DiffCard>
    );
  }

  return (
    <DiffCard label={label} tone={tone}>
      <BeforeAfter
        before={
          <FieldValue
            kind={request.kind}
            field={entry.field}
            value={from}
            categoryNames={categoryNames}
          />
        }
        after={
          <FieldValue
            kind={request.kind}
            field={entry.field}
            value={to}
            categoryNames={categoryNames}
          />
        }
      />
    </DiffCard>
  );
}

/**
 * A coordinate PAIR, or null when either half is missing. Both halves are
 * printed as they arrived: a decimal(10,7) column hands back a string while
 * the wire sends a float, and re-rounding a pin to make the two look alike
 * would be inventing precision the store never typed.
 */
function pair(lat: unknown, lng: unknown): { lat: string; lng: string } | null {
  return isBlank(lat) || isBlank(lng)
    ? null
    : { lat: String(lat), lng: String(lng) };
}

/** The pin as something a reviewer can actually go and look at. */
function PinLink({ at }: { at: { lat: string; lng: string } }) {
  return (
    <a
      href={`https://www.google.com/maps/search/?api=1&query=${at.lat},${at.lng}`}
      target="_blank"
      rel="noreferrer"
      className="inline-flex items-center gap-1 text-primary underline underline-offset-2"
    >
      <MapPin className="size-3.5 shrink-0" />
      {at.lat}, {at.lng}
    </a>
  );
}

/**
 * Latitude and longitude, diffed as ONE thing. Two bare decimals side by
 * side tell a reviewer nothing about whether a shop moved across the street
 * or across the country; a pair of map links does. Rendered only when both
 * halves are in the diff — the snapshot holds just the fields in play, so a
 * lone moved coordinate has no complete "before" to link to and falls back
 * to plain rows.
 */
function PinDiff({
  latitude,
  longitude,
}: {
  latitude: ChangeRequestDiff;
  longitude: ChangeRequestDiff;
}) {
  const before = pair(latitude.from, longitude.from);
  const after = pair(latitude.to, longitude.to);

  return (
    <DiffCard label="Map pin" tone={toneOf(before, after)}>
      <BeforeAfter
        before={
          before === null ? <Blank>No pin</Blank> : <PinLink at={before} />
        }
        after={
          after === null ? (
            <Blank>No pin — this branch would leave Nearby</Blank>
          ) : (
            <PinLink at={after} />
          )
        }
      />
    </DiffCard>
  );
}

/** Keys a branch card lays out by hand; `id` is bookkeeping, not content. */
const BRANCH_LAID_OUT = ['name', 'address', 'lat', 'lng', 'id'];

function BranchLine({
  label,
  children,
}: {
  label: string;
  children: ReactNode;
}) {
  return (
    <div className="flex flex-col gap-0.5">
      <span className="text-[0.6875rem] font-medium tracking-wide text-muted-foreground uppercase">
        {label}
      </span>
      <div className="text-sm break-words">{children}</div>
    </div>
  );
}

/**
 * A whole branch, for the two kinds that have no field-by-field diff: a
 * create has nothing to compare against, and a removal proposes nothing at
 * all — the address itself is what is being decided.
 */
function BranchCard({
  values,
  categoryNames,
}: {
  values: Record<string, unknown>;
  categoryNames: Map<string, string>;
}) {
  const pin = pair(values.lat, values.lng);
  const extras = Object.keys(values).filter(
    (key) => !BRANCH_LAID_OUT.includes(key),
  );

  return (
    <div className="flex flex-col gap-3 rounded-lg border border-border bg-muted/30 p-4">
      <BranchLine label="Branch name">
        {isBlank(values.name) ? <Blank /> : String(values.name)}
      </BranchLine>
      <BranchLine label="Address">
        {isBlank(values.address) ? (
          <Blank />
        ) : (
          <span className="whitespace-pre-wrap">{String(values.address)}</span>
        )}
      </BranchLine>
      <BranchLine label="Map pin">
        {pin === null ? (
          <Blank>No pin — this address cannot appear in Nearby</Blank>
        ) : (
          <PinLink at={pin} />
        )}
      </BranchLine>
      {extras.map((key) => (
        <BranchLine key={key} label={changeFieldLabel('branch_update', key)}>
          <FieldValue
            kind="branch_update"
            field={key}
            value={values[key]}
            categoryNames={categoryNames}
          />
        </BranchLine>
      ))}
    </div>
  );
}

function Note({ children }: { children: ReactNode }) {
  return <p className="text-sm text-muted-foreground">{children}</p>;
}

export function ChangeDiff({ request }: { request: MerchantChangeRequest }) {
  const categoryNames = useCategoryNames();

  if (request.kind === 'branch_create') {
    return (
      <div className="flex flex-col gap-3">
        <Note>
          A brand-new address, so there is nothing to compare it against.
          Approving adds it to the store&apos;s estate and it starts appearing
          in Nearby.
        </Note>
        <BranchCard values={request.proposed} categoryNames={categoryNames} />
      </div>
    );
  }

  if (request.kind === 'branch_delete') {
    return (
      <div className="flex flex-col gap-3">
        <Note>
          A removal proposes no fields — this branch, exactly as it stood when
          the store asked, is the whole of the decision. Approving deletes it;
          the request is refused automatically if transactions or promotions
          have started pointing at it since.
        </Note>
        <BranchCard values={request.current} categoryNames={categoryNames} />
      </div>
    );
  }

  if (request.changes.length === 0) {
    return (
      <Note>
        This request carries no field changes — nothing here would move the
        store.
      </Note>
    );
  }

  // A moved pin is one decision, not two — see PinDiff.
  const latitude = request.changes.find((entry) => entry.field === 'lat');
  const longitude = request.changes.find((entry) => entry.field === 'lng');
  const pinned = latitude !== undefined && longitude !== undefined;
  const rows = pinned
    ? request.changes.filter(
        (entry) => entry.field !== 'lat' && entry.field !== 'lng',
      )
    : request.changes;

  return (
    <div className="flex flex-col gap-3">
      {isBranchKind(request.kind) ? (
        <Note>
          Only the fields the store actually changed are queued; everything else
          on this branch stays as it is.
        </Note>
      ) : null}
      {rows.map((entry) => (
        <DiffRow
          key={entry.field}
          request={request}
          entry={entry}
          categoryNames={categoryNames}
        />
      ))}
      {pinned ? <PinDiff latitude={latitude} longitude={longitude} /> : null}
    </div>
  );
}
