'use client';

import { useEffect, useState, type ReactNode } from 'react';
import Link from 'next/link';
import {
  bpToPercentString,
  dashboardShowsMoney,
  FEE_PROMOTION_BANNER_MAX_CHARS,
  formatPercent,
  getAdminDashboard,
  getAdminFeePromotions,
  parsePercentToBp,
  updateAdminFeePromotions,
  type FeePromotionSettings,
  type IntroductoryFeePromotionSettings,
  type PlatformWideFeePromotionSettings,
  type UpdateFeePromotionSettingsRequest,
} from '@manfaa/api-client';
import { MoneyText } from '@manfaa/ui';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  ArrowRight,
  CalendarRange,
  Info,
  ShieldAlert,
  Sprout,
  TriangleAlert,
} from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage, apiFieldErrors } from '@/lib/api-error';
import { dashboardPresetPeriod, dashboardWindow } from '@/lib/dashboard';
import {
  introFormatIssues,
  introFormDirty,
  introFormFrom,
  introPatch,
  introReadinessIssues,
  introWindowEndMs,
  issueFor,
  scopeLabel,
  unknownBlockers,
  wideFormatIssues,
  wideFormDirty,
  wideFormFrom,
  widePatch,
  wideReadinessIssues,
  wideWindowStatus,
  type FeePromotionField,
  type FeePromotionIssue,
  type IntroFormState,
  type WideFormState,
  type WideWindowStatus,
} from '@/lib/fee-promotions';
import {
  businessToday,
  formatDateTime,
  toIsoWithMaldivesOffset,
  toMaldivesLocalInput,
} from '@/lib/format';
import {
  Alert,
  AlertContent,
  AlertDescription,
  AlertIcon,
  AlertTitle,
} from '@/components/ui/alert';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Card,
  CardContent,
  CardHeader,
  CardHeading,
  CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { PageHeader } from '@/components/admin/page-header';
import { useAdminUser } from '@/components/auth/admin-guard';
import { FeePromotionPreview } from '@/components/settings/fee-promotion-preview';

const FEE_PROMOTIONS_QUERY_KEY = ['admin', 'fee-promotions'] as const;

/**
 * PLATFORM FEE PROMOTIONS (owner, 2026-08-25): "Allow Superadmin to make
 * platform fee promotional or 0 fee during first X days of a merchant on the
 * platform, and also an option for fee promotions app wide… I intend to use
 * this feature during initial merchant acquisition."
 *
 * TWO KINDS ON ONE ROW, one switch each:
 *
 *   INTRODUCTORY   every merchant pays the promotional fee for their first X
 *                  days, counted from the day their store was APPROVED. There
 *                  is no enrolment record and nothing is back-dated: a store
 *                  approved 200 days ago gets nothing from a promotion
 *                  switched on today, because their first X days are over.
 *   PLATFORM-WIDE  a dated window in which EVERY merchant pays the
 *                  promotional fee, whatever their age.
 *
 * When both apply the MERCHANT WINS — the lower fee prices the sale, and no
 * promotion can ever price above the merchant's own §4 tier fee. The API
 * resolves that; nothing on this screen chooses between two offers.
 *
 * FOUR THINGS THIS SCREEN HAS TO SAY OUT LOUD, because the server enforces
 * them and a form that hides them just turns a rule into a 422:
 *
 *  1. "Not set" is not "free". A promotion cannot be enabled without a fee,
 *     and 0.00% is a deliberate value an operator has to type — the whole
 *     point of the feature, and far too consequential to arrive by default.
 *  2. A promotion needs its banner in BOTH languages before it can run. The
 *     banner is how a merchant finds out they are being charged less; a
 *     discount nobody can be told about is an accounting event.
 *  3. A promotional fee above the cheapest tier fee is refused — a merchant
 *     on that tier would pay MORE, which is a typo, not a promotion.
 *  4. Nothing here re-prices a sale that already happened. Every transaction
 *     carries the fee it was rung up under, and every report, settlement and
 *     journal reads that stamp. Switching a promotion on, off, or to another
 *     window prices NEW sales only.
 *
 * READ BY ANY ADMIN, WRITTEN BY A SUPERADMIN. Unlike the GST screen there is
 * no identity to withhold — every field here is destined for a merchant's
 * screen anyway — so a plain admin sees the whole thing, read-only, and the
 * server's EnsureSuperadmin is what actually enforces it.
 *
 * WIRE GRAMMAR (PLAN §1): fees are 2-decimal percent STRINGS in and out.
 * Basis points are computed locally only to bound-check before the 422, and
 * never appear on screen or on the wire.
 */
export default function FeePromotionsPage() {
  const me = useAdminUser();
  const canEdit = me.role === 'superadmin';

  // One instant for the whole screen, so two cards cannot disagree about
  // which side of a window "now" is on while the page is open.
  const [nowMs] = useState(() => Date.now());

  const query = useQuery({
    queryKey: FEE_PROMOTIONS_QUERY_KEY,
    queryFn: ({ signal }) => getAdminFeePromotions({ signal }),
  });

  const data = query.data?.data;

  return (
    <div className="flex flex-col">
      <PageHeader
        title={
          <>
            Fee promotions
            {data ? <KindBadges settings={data} nowMs={nowMs} /> : null}
          </>
        }
        description="A temporary cut in the PLATFORM FEE Manfaa charges a merchant — for every new store’s first days, for a dated campaign across the whole platform, or both. It never touches the customer’s cashback, and it prices new sales only: everything already recorded keeps the fee it was rung up under."
      />

      {!canEdit ? (
        <Alert variant="info" appearance="light" className="mb-5">
          <AlertIcon>
            <ShieldAlert />
          </AlertIcon>
          <AlertContent>
            <AlertTitle>Read-only</AlertTitle>
            <AlertDescription>
              Anyone in the console may read what is on offer — it is marketing,
              and every field here ends up on a merchant’s screen. Changing it
              takes the superadmin role, because one save changes what every
              merchant is charged on every sale from that moment.
            </AlertDescription>
          </AlertContent>
        </Alert>
      ) : null}

      {query.isPending ? (
        <div className="flex flex-col gap-5">
          <Skeleton className="h-64 w-full" />
          <Skeleton className="h-64 w-full" />
        </div>
      ) : query.isError ? (
        <Alert variant="destructive" appearance="light">
          <AlertIcon>
            <TriangleAlert />
          </AlertIcon>
          <AlertDescription>{apiErrorMessage(query.error)}</AlertDescription>
        </Alert>
      ) : data ? (
        <div className="flex flex-col gap-5">
          <CoverageNote settings={data} />
          <IntroductoryCard settings={data} canEdit={canEdit} nowMs={nowMs} />
          <PlatformWideCard settings={data} canEdit={canEdit} nowMs={nowMs} />
          <CostCard />
          {data.updated_at !== null ? (
            <p className="text-xs text-muted-foreground">
              Last saved {formatDateTime(data.updated_at)}.
            </p>
          ) : (
            <p className="text-xs text-muted-foreground">
              Never edited — this is the seeded row, with both offers off.
            </p>
          )}
        </div>
      ) : null}
    </div>
  );
}

/** Live / Off at a glance, for both kinds, in the page title. */
function KindBadges({
  settings,
  nowMs,
}: {
  settings: FeePromotionSettings;
  nowMs: number;
}) {
  const wide = wideWindowStatus(
    settings.platform_wide.from,
    settings.platform_wide.to,
    nowMs,
  );
  const wideLive = settings.platform_wide.enabled && wide === 'running';

  return (
    <>
      <Badge
        variant={settings.intro.enabled ? 'success' : 'secondary'}
        appearance="light"
      >
        Introductory {settings.intro.enabled ? 'on' : 'off'}
      </Badge>
      <Badge variant={wideLive ? 'success' : 'secondary'} appearance="light">
        Platform-wide{' '}
        {wideLive
          ? 'running'
          : settings.platform_wide.enabled
            ? 'on, outside its window'
            : 'off'}
      </Badge>
    </>
  );
}

/**
 * What a promotion covers and what it does not, printed from the payload's
 * own `applies_to` / `excludes` rather than from a sentence written here —
 * the API states them so a screen cannot drift from the seam that enforces
 * them.
 */
function CoverageNote({ settings }: { settings: FeePromotionSettings }) {
  const covers = settings.applies_to.map(scopeLabel).join(', ');
  const excludes = settings.excludes.map(scopeLabel).join(', ');

  return (
    <Alert variant="info" appearance="light" size="sm">
      <AlertIcon>
        <Info />
      </AlertIcon>
      <AlertContent>
        <AlertTitle>
          {covers === ''
            ? 'What a promotion moves'
            : `A promotion moves ${covers} — nothing else`}
        </AlertTitle>
        <AlertDescription>
          {excludes === '' ? null : (
            <>
              It does not touch {excludes}, which are a separate price list on a
              different scale — a shop’s own order fee lives on its marketplace
              profile.{' '}
            </>
          )}
          It never touches the customer’s cashback either: the reward a shopper
          earns is unchanged, and only Manfaa’s cut moves. A promotional fee may
          never exceed the merchant’s own tier fee, so a promotion can only ever
          make a sale cheaper.
        </AlertDescription>
      </AlertContent>
    </Alert>
  );
}

/**
 * The one mutation both cards share. Each card keeps its OWN error text so a
 * refusal appears beside the control that caused it, and Laravel's per-field
 * messages are kept apart so an input can be marked invalid on its own.
 */
function useSaveFeePromotions(successMessage: string) {
  const queryClient = useQueryClient();
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  const mutation = useMutation({
    mutationFn: (body: UpdateFeePromotionSettingsRequest) =>
      updateAdminFeePromotions(body),
    onMutate: () => {
      setError(null);
      setFieldErrors({});
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: FEE_PROMOTIONS_QUERY_KEY });
      toast.success(successMessage);
    },
    onError: (cause) => {
      // The server's own words. Every refusal here is prose written for a
      // person ("A promotion that costs the merchant more is a mistake, not a
      // promotion"), so it is shown verbatim rather than replaced with a
      // friendlier guess.
      setError(apiErrorMessage(cause));
      setFieldErrors(apiFieldErrors(cause));
      toast.error(apiErrorMessage(cause));
    },
  });

  return { mutation, error, fieldErrors };
}

/** The server's refusal, shown in the card that caused it. */
function RefusalAlert({ message }: { message: string | null }) {
  if (message === null) {
    return null;
  }
  return (
    <Alert variant="destructive" appearance="light" size="sm">
      <AlertIcon>
        <TriangleAlert />
      </AlertIcon>
      <AlertDescription>{message}</AlertDescription>
    </Alert>
  );
}

/** Per-input message: the server's field error first, then the local rule. */
function FieldNote({
  issue,
  hint,
}: {
  issue: string | undefined;
  hint?: ReactNode;
}) {
  if (issue !== undefined) {
    return <p className="text-xs text-destructive">{issue}</p>;
  }
  return hint === undefined ? null : (
    <p className="text-xs text-muted-foreground">{hint}</p>
  );
}

/** The promotional fee, as a percent — never basis points, on screen or wire. */
function FeeInput({
  id,
  value,
  onChange,
  issue,
  maxPercent,
  disabled,
}: {
  id: string;
  value: string;
  onChange: (value: string) => void;
  issue: string | undefined;
  maxPercent: string;
  disabled: boolean;
}) {
  return (
    <div className="flex flex-col gap-1.5">
      <Label htmlFor={id}>Promotional platform fee</Label>
      <div className="relative w-40">
        <Input
          id={id}
          inputMode="decimal"
          className="pe-8"
          placeholder="0.00"
          value={value}
          disabled={disabled}
          aria-invalid={issue !== undefined}
          onChange={(event) => onChange(event.target.value)}
        />
        <span className="pointer-events-none absolute inset-y-0 end-3 flex items-center text-sm text-muted-foreground">
          %
        </span>
      </div>
      <FieldNote
        issue={issue}
        hint={
          <>
            {formatPercent(maxPercent)} or less — the cheapest fee on the active
            tier schedule. <strong>0</strong> is legal and is the
            free-for-a-while case; empty means “not set”, and an offer with no
            fee cannot be switched on.
          </>
        }
      />
    </div>
  );
}

/** The banner sentence, in both languages, as the API requires before it runs. */
function BannerFields({
  idPrefix,
  en,
  dv,
  onEn,
  onDv,
  enIssue,
  dvIssue,
  disabled,
}: {
  idPrefix: string;
  en: string;
  dv: string;
  onEn: (value: string) => void;
  onDv: (value: string) => void;
  enIssue: string | undefined;
  dvIssue: string | undefined;
  disabled: boolean;
}) {
  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <div className="flex flex-col gap-1.5">
        <Label htmlFor={`${idPrefix}-banner-en`}>
          Banner wording (English)
        </Label>
        <Textarea
          id={`${idPrefix}-banner-en`}
          rows={2}
          maxLength={FEE_PROMOTION_BANNER_MAX_CHARS}
          value={en}
          disabled={disabled}
          aria-invalid={enIssue !== undefined}
          placeholder="e.g. Your first 90 days on Manfaa are fee-free — you keep every laari of the sale."
          onChange={(event) => onEn(event.target.value)}
        />
        <FieldNote
          issue={enIssue}
          hint={`${en.length}/${FEE_PROMOTION_BANNER_MAX_CHARS} characters. Shown on the merchant panel, in the till app and on the public landing page.`}
        />
      </div>
      <div className="flex flex-col gap-1.5">
        <Label htmlFor={`${idPrefix}-banner-dv`}>
          Banner wording (Dhivehi)
        </Label>
        <Textarea
          id={`${idPrefix}-banner-dv`}
          dir="rtl"
          lang="dv"
          rows={2}
          maxLength={FEE_PROMOTION_BANNER_MAX_CHARS}
          value={dv}
          disabled={disabled}
          aria-invalid={dvIssue !== undefined}
          onChange={(event) => onDv(event.target.value)}
        />
        <FieldNote
          issue={dvIssue}
          hint={`${dv.length}/${FEE_PROMOTION_BANNER_MAX_CHARS} characters. Required — most merchants read the panel in Dhivehi.`}
        />
      </div>
    </div>
  );
}

/**
 * Why the switch is still locked, said before the 422 does — and, when the
 * offer is already LIVE, why saving these details would be refused instead.
 * The two are the same list of rules read from either side of the switch, so
 * they are one alert rather than two competing ones.
 *
 * Local rules and the API's own `blockers[]` agree by construction; a slug
 * this build has never seen is shown raw rather than swallowed, because an
 * unrecognised blocker is still a blocker.
 */
function ReadinessNote({
  issues,
  unknown,
  live,
}: {
  issues: FeePromotionIssue[];
  unknown: string[];
  /** True when the offer is switched on, which changes what this means. */
  live: boolean;
}) {
  if (issues.length === 0 && unknown.length === 0) {
    return null;
  }

  return (
    <Alert
      variant={live ? 'destructive' : 'warning'}
      appearance="light"
      size="sm"
    >
      <AlertIcon>
        <TriangleAlert />
      </AlertIcon>
      <AlertContent>
        <AlertTitle>
          {live
            ? 'These details would not be allowed to run'
            : 'Not ready to switch on'}
        </AlertTitle>
        <AlertDescription className="flex flex-col gap-2">
          <ul className="list-disc space-y-1 ps-4">
            {issues.map((issue) => (
              <li key={`${issue.field}-${issue.message}`}>{issue.message}</li>
            ))}
            {unknown.map((slug) => (
              <li key={slug}>
                The API is reporting a condition this screen does not know how
                to explain: <code>{slug}</code>.
              </li>
            ))}
          </ul>
          {live ? (
            <span>
              The offer is live, so saving them as they stand will be refused.
              Fix them, or switch the offer off first.
            </span>
          ) : null}
        </AlertDescription>
      </AlertContent>
    </Alert>
  );
}

/** The switch row: the plain sentence of what it does, and the switch. */
function SwitchRow({
  label,
  enabled,
  disabled,
  onChange,
  children,
}: {
  label: string;
  enabled: boolean;
  disabled: boolean;
  onChange: (next: boolean) => void;
  children: ReactNode;
}) {
  return (
    <div className="flex items-start justify-between gap-6">
      <div className="max-w-3xl text-sm">{children}</div>
      <Switch
        aria-label={label}
        checked={enabled}
        disabled={disabled}
        onCheckedChange={onChange}
      />
    </div>
  );
}

/** Save / dirty affordances, identical on both cards. */
function SaveRow({
  dirty,
  disabled,
  pending,
  onSave,
}: {
  dirty: boolean;
  disabled: boolean;
  pending: boolean;
  onSave: () => void;
}) {
  return (
    <div className="flex items-center gap-3">
      <Button disabled={disabled} onClick={onSave}>
        {pending ? 'Saving…' : 'Save details'}
      </Button>
      {dirty ? (
        <span className="text-xs text-muted-foreground">
          Unsaved. The switch reads the saved row — turning the offer on saves
          these details with it.
        </span>
      ) : null}
    </div>
  );
}

// ---------------------------------------------------------------------------
// Introductory
// ---------------------------------------------------------------------------

/**
 * EVERY NEW MERCHANT'S FIRST X DAYS, counted from `merchants.approved_at` —
 * the stamp that says the store could actually trade.
 *
 * The screen states the non-retro rule twice, in the card and again in the
 * confirmation, because it is the question an owner asks first and the
 * answer is counter-intuitive: switching this on today does nothing at all
 * for a merchant approved a year ago. Their first X days are their first X
 * days.
 */
function IntroductoryCard({
  settings,
  canEdit,
  nowMs,
}: {
  settings: FeePromotionSettings;
  canEdit: boolean;
  nowMs: number;
}) {
  const intro: IntroductoryFeePromotionSettings = settings.intro;
  const { mutation, error, fieldErrors } = useSaveFeePromotions(
    'Introductory offer saved.',
  );

  const [form, setForm] = useState<IntroFormState>(() => introFormFrom(intro));
  const [pendingChange, setPendingChange] = useState<boolean | null>(null);

  // Re-sync when a write lands — this admin's or another's. The dependencies
  // are the saved VALUES, never the object a refetch always replaces, so an
  // unchanged answer does not wipe what is being typed.
  useEffect(() => {
    setForm({
      fee: intro.platform_fee_percent ?? '',
      days: String(intro.days),
      bannerEn: intro.banner_en ?? '',
      bannerDv: intro.banner_dv ?? '',
    });
  }, [
    intro.platform_fee_percent,
    intro.days,
    intro.banner_en,
    intro.banner_dv,
  ]);

  const dirty = introFormDirty(form, intro);
  const formatIssues = introFormatIssues(
    form,
    settings.max_promotional_fee_percent,
  );
  const readiness = introReadinessIssues(
    form,
    settings.max_promotional_fee_percent,
  );
  const unknown = unknownBlockers(intro.blockers);

  // RED MEANS WRONG, NOT MERELY UNFINISHED. A field is marked invalid for
  // what the server refused, for text that is not a percent or a whole number
  // of days — and, while the offer is LIVE, for anything that would stop it
  // running. On an offer that is off, "no fee yet" and "no wording yet" are
  // simply where a blank row starts, and they are listed once in the amber
  // readiness note instead of painting the whole card red on first sight.
  const issue = (field: FeePromotionField) =>
    fieldErrors[field] ??
    (intro.enabled
      ? issueFor(readiness, field)
      : issueFor(formatIssues, field));

  const feeBp = parsePercentToBp(form.fee.trim());
  const feePercent = feeBp === null ? null : bpToPercentString(feeBp);
  const days = Number(form.days.trim());
  const previewEnd =
    Number.isInteger(days) && days > 0 ? introWindowEndMs(nowMs, days) : null;

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle className="flex items-center gap-2.5">
            <Sprout className="size-4 text-muted-foreground" />
            Introductory offer — every new merchant’s first days
          </CardTitle>
        </CardHeading>
        <Badge
          variant={intro.enabled ? 'success' : 'secondary'}
          appearance="light"
          size="sm"
        >
          {intro.enabled ? 'On' : 'Off'}
        </Badge>
      </CardHeader>
      <CardContent className="flex flex-col gap-5">
        <SwitchRow
          label="Run the introductory offer"
          enabled={intro.enabled}
          disabled={
            !canEdit ||
            mutation.isPending ||
            (!intro.enabled && (readiness.length > 0 || unknown.length > 0))
          }
          onChange={setPendingChange}
        >
          {intro.enabled ? (
            <>
              <p className="font-medium">
                Every merchant is charged{' '}
                {intro.platform_fee_percent === null
                  ? '—'
                  : formatPercent(intro.platform_fee_percent)}{' '}
                platform fee for their first {intro.days}{' '}
                {intro.days === 1 ? 'day' : 'days'} on the platform.
              </p>
              <p className="text-muted-foreground">
                Counted from the day their store was approved, day one included.
                A store whose first {intro.days} days are already behind it pays
                its ordinary tier fee — nothing is back-enrolled.
              </p>
            </>
          ) : (
            <>
              <p className="font-medium">
                Switching this on charges every merchant{' '}
                {feePercent === null ? '—' : formatPercent(feePercent)} platform
                fee for their first {Number.isInteger(days) ? days : '—'}{' '}
                {days === 1 ? 'day' : 'days'}, counted from the day their store
                was approved.
              </p>
              <p className="text-muted-foreground">
                It applies to merchants already on the platform only insofar as
                they are still inside their own first days: a store approved
                three days ago gets the rest of the window, and one approved
                last year gets nothing.
              </p>
            </>
          )}
        </SwitchRow>

        <div className="grid gap-4 sm:grid-cols-2">
          <FeeInput
            id="intro-fee"
            value={form.fee}
            onChange={(fee) => setForm({ ...form, fee })}
            issue={issue('intro_fee_percent')}
            maxPercent={settings.max_promotional_fee_percent}
            disabled={!canEdit || mutation.isPending}
          />

          <div className="flex flex-col gap-1.5">
            <Label htmlFor="intro-days">Days from approval</Label>
            <Input
              id="intro-days"
              className="w-40"
              inputMode="numeric"
              value={form.days}
              disabled={!canEdit || mutation.isPending}
              aria-invalid={issue('intro_days') !== undefined}
              onChange={(event) =>
                setForm({ ...form, days: event.target.value })
              }
            />
            <FieldNote
              issue={issue('intro_days')}
              hint="Whole Maldivian days, the approval day included. 30 means the store’s approval day plus the 29 days after it."
            />
          </div>
        </div>

        <BannerFields
          idPrefix="intro"
          en={form.bannerEn}
          dv={form.bannerDv}
          onEn={(bannerEn) => setForm({ ...form, bannerEn })}
          onDv={(bannerDv) => setForm({ ...form, bannerDv })}
          enIssue={issue('intro_banner_en')}
          dvIssue={issue('intro_banner_dv')}
          disabled={!canEdit || mutation.isPending}
        />

        <FeePromotionPreview
          kind="introductory"
          feePercent={feePercent}
          introDays={Number.isInteger(days) ? days : null}
          endsAtMs={previewEnd}
          bannerEn={form.bannerEn}
          bannerDv={form.bannerDv}
          nowMs={nowMs}
        />

        {canEdit ? (
          <ReadinessNote
            issues={readiness}
            unknown={unknown}
            live={intro.enabled}
          />
        ) : null}

        <RefusalAlert message={error} />

        {canEdit ? (
          <SaveRow
            dirty={dirty}
            disabled={!dirty || formatIssues.length > 0 || mutation.isPending}
            pending={mutation.isPending}
            onSave={() => mutation.mutate(introPatch(form))}
          />
        ) : null}
      </CardContent>

      <AlertDialog
        open={pendingChange !== null}
        onOpenChange={(open) => {
          if (!open) {
            setPendingChange(null);
          }
        }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              {pendingChange === true
                ? `Charge every new merchant ${feePercent === null ? '—' : formatPercent(feePercent)} for their first ${days} ${days === 1 ? 'day' : 'days'}?`
                : 'Turn the introductory offer off?'}
            </AlertDialogTitle>
            <AlertDialogDescription asChild>
              {pendingChange === true ? (
                <div className="flex flex-col gap-2 text-start">
                  <p>
                    <strong>
                      Every merchant inside their first {days}{' '}
                      {days === 1 ? 'day' : 'days'} pays{' '}
                      {feePercent === null ? '—' : formatPercent(feePercent)}
                    </strong>{' '}
                    on every sale rung up from now on, instead of their §4 tier
                    fee. The window runs from the day their store was approved —
                    so it covers new stores in full and stores approved recently
                    for whatever is left of it.
                  </p>
                  <p>
                    <strong>Nobody is back-enrolled.</strong> A store whose
                    first {days} {days === 1 ? 'day' : 'days'} already passed
                    gets nothing, today or ever, from this offer.
                  </p>
                  <p>
                    <strong>Existing sales are untouched.</strong> Each keeps
                    the fee it was rung up under — no settlement, report or
                    journal entry is restated.
                  </p>
                  <p>
                    <strong>Merchants are told by your banner</strong>, on the
                    panel, in the till app and on the public landing page. No
                    other notification is sent.
                  </p>
                  {dirty ? (
                    <p>
                      Your unsaved changes on this card are saved with the same
                      click.
                    </p>
                  ) : null}
                </div>
              ) : (
                <div className="flex flex-col gap-2 text-start">
                  <p>
                    From the next sale, every merchant pays their own §4 tier
                    fee again — including stores still inside their first{' '}
                    {intro.days} days, who lose the rest of it immediately, and
                    whose banner disappears from the panel and the till app.
                  </p>
                  <p>
                    Sales already priced keep their promotional fee, and the fee
                    forgone so far stays on the reports. Nothing is reclaimed.
                  </p>
                  {dirty ? (
                    <p>Your unsaved changes on this card are not saved.</p>
                  ) : null}
                </div>
              )}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              variant={pendingChange === true ? undefined : 'destructive'}
              onClick={() => {
                if (pendingChange === true) {
                  mutation.mutate({ ...introPatch(form), intro_enabled: true });
                } else if (pendingChange === false) {
                  mutation.mutate({ intro_enabled: false });
                }
                setPendingChange(null);
              }}
            >
              {pendingChange === true
                ? 'Turn the introductory offer on'
                : 'Turn it off'}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </Card>
  );
}

// ---------------------------------------------------------------------------
// Platform-wide
// ---------------------------------------------------------------------------

const WIDE_STATUS_LABEL: Record<
  WideWindowStatus,
  { text: string; variant: 'success' | 'info' | 'secondary' | 'warning' }
> = {
  unset: { text: 'No window', variant: 'secondary' },
  scheduled: { text: 'Window not open yet', variant: 'info' },
  running: { text: 'Window open', variant: 'success' },
  ended: { text: 'Window closed', variant: 'warning' },
};

/**
 * A DATED CAMPAIGN ACROSS THE WHOLE PLATFORM — every merchant, whatever
 * their age, between two instants.
 *
 * Enabled and running are two different questions, and the card answers
 * both: an enabled offer whose window has closed prices nothing at all, and
 * one whose window has not opened changes nothing until it does. The badge
 * says which, so a superadmin does not read a green switch as money going
 * out of the door when it is not — or, worse, the other way round.
 */
function PlatformWideCard({
  settings,
  canEdit,
  nowMs,
}: {
  settings: FeePromotionSettings;
  canEdit: boolean;
  nowMs: number;
}) {
  const wide: PlatformWideFeePromotionSettings = settings.platform_wide;
  const { mutation, error, fieldErrors } = useSaveFeePromotions(
    'Platform-wide offer saved.',
  );

  const [form, setForm] = useState<WideFormState>(() => wideFormFrom(wide));
  const [pendingChange, setPendingChange] = useState<boolean | null>(null);

  useEffect(() => {
    setForm({
      fee: wide.platform_fee_percent ?? '',
      from: wide.from === null ? '' : toMaldivesLocalInput(new Date(wide.from)),
      to: wide.to === null ? '' : toMaldivesLocalInput(new Date(wide.to)),
      bannerEn: wide.banner_en ?? '',
      bannerDv: wide.banner_dv ?? '',
    });
  }, [
    wide.platform_fee_percent,
    wide.from,
    wide.to,
    wide.banner_en,
    wide.banner_dv,
  ]);

  const dirty = wideFormDirty(form, wide);
  const formatIssues = wideFormatIssues(
    form,
    settings.max_promotional_fee_percent,
  );
  const readiness = wideReadinessIssues(
    form,
    settings.max_promotional_fee_percent,
  );
  const unknown = unknownBlockers(wide.blockers);

  // As on the introductory card: invalid ink is for what is WRONG, not for
  // what is merely still blank on an offer nobody has switched on.
  const issue = (field: FeePromotionField) =>
    fieldErrors[field] ??
    (wide.enabled ? issueFor(readiness, field) : issueFor(formatIssues, field));

  const feeBp = parsePercentToBp(form.fee.trim());
  const feePercent = feeBp === null ? null : bpToPercentString(feeBp);

  const savedStatus = wideWindowStatus(wide.from, wide.to, nowMs);
  const status = WIDE_STATUS_LABEL[savedStatus];

  // The FORM's window — what the switch is about to commit to, not what is
  // currently stored. Read as MALDIVES wall clock, which is what the labels
  // above the two inputs promise.
  const formFromIso = toIsoWithMaldivesOffset(form.from);
  const formToIso = toIsoWithMaldivesOffset(form.to);
  const formToMs = formToIso === null ? null : new Date(formToIso).getTime();
  const formStatus = wideWindowStatus(formFromIso, formToIso, nowMs);

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle className="flex items-center gap-2.5">
            <CalendarRange className="size-4 text-muted-foreground" />
            Platform-wide offer — every merchant, between two dates
          </CardTitle>
        </CardHeading>
        <div className="flex flex-wrap items-center gap-2">
          <Badge
            variant={wide.enabled ? 'success' : 'secondary'}
            appearance="light"
            size="sm"
          >
            {wide.enabled ? 'On' : 'Off'}
          </Badge>
          <Badge variant={status.variant} appearance="light" size="sm">
            {status.text}
          </Badge>
        </div>
      </CardHeader>
      <CardContent className="flex flex-col gap-5">
        <SwitchRow
          label="Run the platform-wide offer"
          enabled={wide.enabled}
          disabled={
            !canEdit ||
            mutation.isPending ||
            (!wide.enabled && (readiness.length > 0 || unknown.length > 0))
          }
          onChange={setPendingChange}
        >
          {wide.enabled ? (
            <>
              <p className="font-medium">
                Every merchant is charged{' '}
                {wide.platform_fee_percent === null
                  ? '—'
                  : formatPercent(wide.platform_fee_percent)}{' '}
                platform fee on sales between {formatDateTime(wide.from)} and{' '}
                {formatDateTime(wide.to)}.
              </p>
              <p className="text-muted-foreground">
                {savedStatus === 'running'
                  ? 'The window is open: sales are being priced at the promotional fee right now.'
                  : savedStatus === 'scheduled'
                    ? 'The window has not opened yet — nothing changes for anyone until it does.'
                    : savedStatus === 'ended'
                      ? 'The window has closed, so this switch is pricing nothing. Move the dates or switch it off.'
                      : 'There is no usable window on the saved row, so this switch is pricing nothing.'}{' '}
                Age makes no difference here: a store approved this morning and
                one approved two years ago pay the same fee.
              </p>
            </>
          ) : (
            <>
              <p className="font-medium">
                Switching this on charges every merchant{' '}
                {feePercent === null ? '—' : formatPercent(feePercent)} platform
                fee on every sale between the two dates below, whatever their
                age.
              </p>
              <p className="text-muted-foreground">
                A merchant inside their introductory window keeps whichever of
                the two fees is lower — the merchant always wins, and no
                promotion can price above their own tier fee.
              </p>
            </>
          )}
        </SwitchRow>

        <div className="grid gap-4 sm:grid-cols-3">
          <FeeInput
            id="wide-fee"
            value={form.fee}
            onChange={(fee) => setForm({ ...form, fee })}
            issue={issue('wide_fee_percent')}
            maxPercent={settings.max_promotional_fee_percent}
            disabled={!canEdit || mutation.isPending}
          />

          <div className="flex flex-col gap-1.5">
            <Label htmlFor="wide-from">Starts (Maldives time)</Label>
            <Input
              id="wide-from"
              type="datetime-local"
              value={form.from}
              disabled={!canEdit || mutation.isPending}
              aria-invalid={issue('wide_from') !== undefined}
              onChange={(event) =>
                setForm({ ...form, from: event.target.value })
              }
            />
            <FieldNote
              issue={issue('wide_from')}
              hint="The first instant a sale is priced at the promotional fee."
            />
          </div>

          <div className="flex flex-col gap-1.5">
            <Label htmlFor="wide-to">Ends (Maldives time)</Label>
            <Input
              id="wide-to"
              type="datetime-local"
              value={form.to}
              disabled={!canEdit || mutation.isPending}
              aria-invalid={issue('wide_to') !== undefined}
              onChange={(event) => setForm({ ...form, to: event.target.value })}
            />
            <FieldNote
              issue={issue('wide_to')}
              hint="Exclusive: the first instant the offer no longer prices a sale. To run it through the 30th, set 00:00 on the 1st — merchants are shown the last day it applies, never this instant."
            />
          </div>
        </div>

        <BannerFields
          idPrefix="wide"
          en={form.bannerEn}
          dv={form.bannerDv}
          onEn={(bannerEn) => setForm({ ...form, bannerEn })}
          onDv={(bannerDv) => setForm({ ...form, bannerDv })}
          enIssue={issue('wide_banner_en')}
          dvIssue={issue('wide_banner_dv')}
          disabled={!canEdit || mutation.isPending}
        />

        <FeePromotionPreview
          kind="platform_wide"
          feePercent={feePercent}
          introDays={null}
          endsAtMs={formToMs}
          bannerEn={form.bannerEn}
          bannerDv={form.bannerDv}
          nowMs={nowMs}
        />

        {formStatus === 'ended' ? (
          <Alert variant="warning" appearance="light" size="sm">
            <AlertIcon>
              <TriangleAlert />
            </AlertIcon>
            <AlertDescription>
              This window has already closed, so the offer would price nothing —
              the API allows it, but no merchant would ever see it.
            </AlertDescription>
          </Alert>
        ) : null}

        {canEdit ? (
          <ReadinessNote
            issues={readiness}
            unknown={unknown}
            live={wide.enabled}
          />
        ) : null}

        <RefusalAlert message={error} />

        {canEdit ? (
          <SaveRow
            dirty={dirty}
            disabled={!dirty || formatIssues.length > 0 || mutation.isPending}
            pending={mutation.isPending}
            onSave={() => mutation.mutate(widePatch(form))}
          />
        ) : null}
      </CardContent>

      <AlertDialog
        open={pendingChange !== null}
        onOpenChange={(open) => {
          if (!open) {
            setPendingChange(null);
          }
        }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              {pendingChange === true
                ? `Charge every merchant ${feePercent === null ? '—' : formatPercent(feePercent)} between these dates?`
                : 'Turn the platform-wide offer off?'}
            </AlertDialogTitle>
            <AlertDialogDescription asChild>
              {pendingChange === true ? (
                <div className="flex flex-col gap-2 text-start">
                  <p>
                    <strong>
                      Every merchant on the platform pays{' '}
                      {feePercent === null ? '—' : formatPercent(feePercent)}
                    </strong>{' '}
                    on every sale rung up between {formatDateTime(formFromIso)}{' '}
                    and {formatDateTime(formToIso)} — new stores and old ones
                    alike.
                    {formStatus === 'scheduled'
                      ? ' The window has not opened yet, so nothing changes until it does.'
                      : formStatus === 'running'
                        ? ' The window is already open, so the next sale is priced at the promotional fee.'
                        : ''}
                  </p>
                  <p>
                    <strong>A merchant never pays more.</strong> Where an
                    introductory offer is also running, the lower of the two
                    fees prices the sale, and neither may exceed the store’s own
                    tier fee.
                  </p>
                  <p>
                    <strong>Existing sales are untouched</strong>, and merchants
                    are told by your banner — panel, till app and public landing
                    page. No other notification is sent.
                  </p>
                  {dirty ? (
                    <p>
                      Your unsaved changes on this card are saved with the same
                      click.
                    </p>
                  ) : null}
                </div>
              ) : (
                <div className="flex flex-col gap-2 text-start">
                  <p>
                    From the next sale every merchant pays their own §4 tier fee
                    again, even though the window itself has not moved, and the
                    banner disappears from the panel and the till app.
                  </p>
                  <p>
                    Sales already priced keep their promotional fee, and the fee
                    forgone so far stays on the reports. A credit keyed in later
                    for a date inside the window is priced WITHOUT the
                    promotion: once the switch is off it prices nothing at all.
                  </p>
                  {dirty ? (
                    <p>Your unsaved changes on this card are not saved.</p>
                  ) : null}
                </div>
              )}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              variant={pendingChange === true ? undefined : 'destructive'}
              onClick={() => {
                if (pendingChange === true) {
                  mutation.mutate({ ...widePatch(form), wide_enabled: true });
                } else if (pendingChange === false) {
                  mutation.mutate({ wide_enabled: false });
                }
                setPendingChange(null);
              }}
            >
              {pendingChange === true
                ? 'Turn the platform-wide offer on'
                : 'Turn it off'}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </Card>
  );
}

// ---------------------------------------------------------------------------
// What it is costing
// ---------------------------------------------------------------------------

/**
 * WHAT THE RUNNING PROMOTIONS HAVE COST, so the acquisition spend is visible
 * on the screen that spends it.
 *
 * The figure is NOT computed here: it is the dashboard's own
 * `fee_forgone_to_promotions_laari`, which is read from the cashback report,
 * which derives it at PRICING time — the tier fee the sale would have paid,
 * less the promotional fee it actually paid, both after the same GST split.
 * The same query key as the dashboard's default window, so the two screens
 * share one read and can never disagree.
 *
 * It is a MEMO figure on the SALE clock. A fee never charged posts no
 * journal, so it is not part of platform fee revenue and must never be
 * subtracted from it — the tile says so, in the same words the API's own
 * docblock uses.
 *
 * Superadmin-only, like every money figure: the endpoint omits the block for
 * anyone else, and the card is then not rendered rather than rendered as
 * zero.
 */
function CostCard() {
  const me = useAdminUser();
  const [today] = useState(businessToday);
  const period = dashboardPresetPeriod('this_month', today);

  const query = useQuery({
    queryKey: ['admin', 'dashboard', period.from, period.to],
    queryFn: ({ signal }) =>
      getAdminDashboard(dashboardWindow(period), { signal }),
    // The dashboard is a heavy read and its money block is superadmin-only
    // anyway: for anyone else the answer would arrive without the figure this
    // card exists for, so the request is not made at all.
    enabled: me.role === 'superadmin',
    staleTime: 30_000,
  });

  const dashboard = query.data;

  if (dashboard === undefined || !dashboardShowsMoney(dashboard)) {
    return null;
  }

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle>What the promotions are costing</CardTitle>
        </CardHeading>
        <span className="text-sm text-muted-foreground">
          {formatDateTime(dashboard.generated_at)}
        </span>
      </CardHeader>
      <CardContent className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="flex flex-col gap-1">
          <span className="text-xs font-medium text-muted-foreground uppercase">
            Fee forgone to promotions
          </span>
          <MoneyText
            laari={dashboard.money.fee_forgone_to_promotions_laari}
            className="text-2xl font-semibold"
          />
          <span className="max-w-xl text-xs text-muted-foreground">
            Sales in this period ({dashboard.period.from} to{' '}
            {dashboard.period.to}, by the date of the sale): what those sales
            would have paid at each merchant’s own tier fee, less what a
            promotion actually charged them. A memo figure — a fee never charged
            posts no journal, so it is neither part of platform fee revenue nor
            a deduction from it.
          </span>
        </div>

        <div className="flex shrink-0 flex-col gap-2">
          <Button variant="outline" asChild>
            <Link href="/dashboard">
              Dashboard money tiles
              <ArrowRight />
            </Link>
          </Button>
          <Button variant="outline" asChild>
            <Link href="/reports">
              Cashback report
              <ArrowRight />
            </Link>
          </Button>
          <span className="max-w-56 text-[0.6875rem] leading-snug text-muted-foreground">
            The cashback report carries it per transaction and in its totals,
            and exports with the sheet.
          </span>
        </div>
      </CardContent>
    </Card>
  );
}
