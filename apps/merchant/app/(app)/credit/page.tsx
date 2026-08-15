'use client';

import { useEffect, useMemo, useState } from 'react';
import {
  ApiError,
  bpToPercentString,
  parseMvrToLaari,
  parsePercentToBp,
  percentToBp,
  type ProductCategory,
  type Transaction,
} from '@manfaa/api-client';
import { MoneyText } from '@manfaa/ui';
import { REGEXP_ONLY_DIGITS } from 'input-otp';
import {
  BadgeCheck,
  CircleCheck,
  Clock4,
  HandCoins,
  LoaderCircle,
  SearchX,
  TriangleAlert,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import {
  estimateFeeBpFor,
  estimateLaariAtBp,
  formatBp,
  formatRate,
  formatRateOrDash,
} from '@/lib/estimate';
import {
  advertisedRatePercent,
  apiErrorMessage,
  isRateBelowAdvertised,
  isRateNotPriced,
  rateNotPricedMessage,
  useCreateCredit,
  useCustomerLookup,
  useProductCategories,
  usePromotions,
  useRate,
} from '@/lib/queries';
import { hasRoleAtLeast } from '@/lib/roles';
import {
  Alert,
  AlertContent,
  AlertDescription,
  AlertIcon,
  AlertTitle,
} from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input, InputAddon, InputGroup } from '@/components/ui/input';
import {
  InputOTP,
  InputOTPGroup,
  InputOTPSlot,
} from '@/components/ui/input-otp';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';
import { useLayout } from '@/components/app-layout/context';
import {
  Toolbar,
  ToolbarDescription,
  ToolbarHeading,
  ToolbarPageTitle,
} from '@/components/app-layout/toolbar';
import {
  analyzeSplit,
  DEFAULT_BUCKET,
  ResultLines,
  SplitEditor,
  type SplitRow,
} from '@/components/app/credit-split';
import { QrScanButton } from '@/components/app/qr-scanner';
import {
  TransactionReasonLine,
  TransactionStateBadge,
} from '@/components/app/state-badge';

/**
 * The counter screen (§10, original-spec §8 manual credit path): a staff
 * member keys in a sale by hand. The server owns every rule — rate frozen
 * at occurred_at, ceiling rounding, below-minimum zeroing, stale-timestamp
 * hold, duplicate-invoice rejection. This screen confirms the customer by
 * masked name first (§11 phone-recycling control) and previews the cost as
 * an ESTIMATE at the current rate only.
 *
 * Two PLAN §1 capabilities live here:
 *
 *  - **Custom cashback for this sale** — an optional, collapsed-by-default
 *    `cashback_rate_percent` that applies to THIS sale only, for MANAGERS
 *    and owners (staff key sales in at the store's own terms; the API
 *    answers 403 `manager_required` otherwise). It may only RAISE what the
 *    sale already earns (the standing rate, or a live promotion): the
 *    advertised rate is a public promise, and the customer is told the
 *    higher figure. The server re-checks both bounds and answers
 *    `rate_below_advertised` / `rate_not_priced`.
 *  - **Sale time is optional** — leave the field empty and the server
 *    records the sale as now. An empty field is never sent as an empty
 *    string; the key is simply absent.
 */

/** Maldives is UTC+05:00 year-round — occurred_at is sent with it explicit. */
const BUSINESS_UTC_OFFSET = '+05:00';
const BUSINESS_OFFSET_MS = 5 * 60 * 60 * 1000;

/** §4 structural bounds for a per-sale rate, integer bp (§4 / PLAN §1). */
const MIN_RATE_BP = 50;
const MAX_RATE_BP = 2000;

/**
 * §1 default refund/validation window, which is ALSO the ceiling a
 * merchant's own window is validated against (PreferencesController caps it
 * at the platform default) — so a store's window is somewhere in 0…this.
 *
 * The panel cannot read the actual number: GET-ing it means the owner-only
 * preferences route, and this screen is staff work. Hence the two-band
 * warning below, derived from the BOUNDS. If an admin ever raises the
 * platform default above 3, the upper band starts firing a few days early
 * — the warning is then over-cautious rather than absent, and the real fix
 * is to expose validation_window_days on a staff-readable endpoint.
 */
const DEFAULT_VALIDATION_WINDOW_DAYS = 3;

/**
 * Grace days the server adds to the window before a credit counts as
 * BACKDATED (CreditRecorder::STALE_GRACE_DAYS). A backdated credit skips
 * on_hold entirely: payable immediately, and merchant-irreversible for good
 * (PLAN §1 "Backdated credits").
 */
const BACKDATED_GRACE_DAYS = 3;

const DAY_MS = 24 * 60 * 60 * 1000;

/** The server's reason code on a backdated, irreversible credit. */
const BACKDATED_REASON = 'backdated_final';

/** Now as a datetime-local string ("YYYY-MM-DDTHH:mm") in UTC+5 wall time. */
function nowLocalValue(): string {
  return new Date(Date.now() + BUSINESS_OFFSET_MS).toISOString().slice(0, 16);
}

/** "YYYY-MM-DDTHH:mm[:ss]" (business wall time) → ISO 8601 with +05:00. */
function toBusinessIso(localValue: string): string {
  const withSeconds =
    localValue.length === 16 ? `${localValue}:00` : localValue;
  return `${withSeconds}${BUSINESS_UTC_OFFSET}`;
}

/** parseMvrToLaari without the throw: null when not a valid MVR amount. */
function safeParseMvr(input: string): number | null {
  try {
    return parseMvrToLaari(input);
  } catch {
    return null;
  }
}

interface CreditResult {
  transaction: Transaction;
  maskedName: string;
}

function LookupCard({ code }: { code: string }) {
  const lookup = useCustomerLookup(code);

  if (!/^\d{6}$/.test(code)) {
    return (
      <p className="text-sm text-muted-foreground">
        Ask the customer for the 6-digit code shown in their Manfaa app.
      </p>
    );
  }

  if (lookup.isPending) {
    return (
      <div className="flex items-center gap-2 text-sm text-muted-foreground">
        <LoaderCircle className="size-4 animate-spin" />
        Checking code…
      </div>
    );
  }

  if (lookup.error) {
    return (
      <Alert variant="destructive" appearance="light">
        <AlertIcon>
          <TriangleAlert />
        </AlertIcon>
        <AlertTitle>
          {apiErrorMessage(
            lookup.error,
            'Could not check this code — try again.',
          )}
        </AlertTitle>
      </Alert>
    );
  }

  if (!lookup.data?.valid) {
    return (
      <Alert variant="warning" appearance="light">
        <AlertIcon>
          <SearchX />
        </AlertIcon>
        <AlertContent>
          <AlertTitle>We don&apos;t recognise this code</AlertTitle>
          <AlertDescription>
            Check the digits with the customer before trying again — a typo here
            credits a stranger.
          </AlertDescription>
        </AlertContent>
      </Alert>
    );
  }

  return (
    <Alert variant="success" appearance="light">
      <AlertIcon>
        <BadgeCheck />
      </AlertIcon>
      <AlertContent>
        <AlertTitle>Crediting {lookup.data.masked_name}</AlertTitle>
        <AlertDescription>
          Confirm this is the person in front of you before submitting.
        </AlertDescription>
      </AlertContent>
    </Alert>
  );
}

function ResultCard({
  result,
  categories,
  onReset,
}: {
  result: CreditResult;
  /** For dv names on the priced lines; [] is always safe. */
  categories: ProductCategory[];
  onReset: () => void;
}) {
  const { t } = useTranslation();
  const { transaction } = result;
  const belowMinimum =
    transaction.state === 'reversed' &&
    transaction.reason_code === 'below_minimum';
  const underReview = transaction.state === 'on_hold';
  /**
   * PLAN §1: the sale was outside the validation window, so it never sat in
   * one — it is payable now and can no longer be reversed from this panel or
   * the merchant's POS.
   */
  const backdatedFinal = transaction.reason_code === BACKDATED_REASON;

  return (
    <Card className="mb-5">
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          {belowMinimum ? (
            <>
              <TriangleAlert className="size-4.5 text-yellow-500" />
              Recorded — no reward
            </>
          ) : underReview ? (
            <>
              <Clock4 className="size-4.5 text-violet-500" />
              Recorded — under review
            </>
          ) : backdatedFinal ? (
            <>
              <TriangleAlert className="size-4.5 text-yellow-500" />
              {t('credit.backdatedResultTitle')}
            </>
          ) : (
            <>
              <CircleCheck className="size-4.5 text-green-500" />
              Cashback recorded
            </>
          )}
        </CardTitle>
        <Button variant="outline" size="sm" onClick={onReset}>
          Credit another customer
        </Button>
      </CardHeader>
      <CardContent className="flex flex-col gap-3 text-sm">
        {belowMinimum && (
          <Alert variant="warning" appearance="light">
            <AlertIcon>
              <TriangleAlert />
            </AlertIcon>
            <AlertContent>
              <AlertTitle>
                This sale is below your store&apos;s minimum eligible amount.
              </AlertTitle>
              <AlertDescription>
                It was recorded for the books with zero cashback and closed —
                the customer earns nothing on it and nothing is payable.
              </AlertDescription>
            </AlertContent>
          </Alert>
        )}
        {underReview && (
          <Alert variant="info" appearance="light">
            <AlertIcon>
              <Clock4 />
            </AlertIcon>
            <AlertContent>
              <AlertTitle>On hold — Manfaa is checking it.</AlertTitle>
              <AlertDescription>
                The sale is recorded and on hold for a review. It counts for the
                customer once the review clears it; nothing else is needed from
                you.
              </AlertDescription>
            </AlertContent>
          </Alert>
        )}
        {backdatedFinal && (
          <Alert variant="warning" appearance="light">
            <AlertIcon>
              <TriangleAlert />
            </AlertIcon>
            <AlertContent>
              <AlertTitle>{t('credit.backdatedResultTitle')}</AlertTitle>
              <AlertDescription>
                {t('credit.backdatedResultBody')}
              </AlertDescription>
            </AlertContent>
          </Alert>
        )}

        <div className="grid grid-cols-2 gap-x-6 gap-y-1.5 sm:grid-cols-3">
          <span className="text-muted-foreground">Customer</span>
          <span className="sm:col-span-2">{result.maskedName}</span>
          <span className="text-muted-foreground">Invoice</span>
          <span className="sm:col-span-2 text-mono">
            {transaction.invoice_no}
          </span>
          <span className="text-muted-foreground">State</span>
          <span className="sm:col-span-2">
            <TransactionStateBadge state={transaction.state} />
            <TransactionReasonLine
              code={transaction.reason_code}
              className="ms-2 text-xs text-muted-foreground"
            />
          </span>
          <span className="text-muted-foreground">Eligible amount</span>
          <MoneyText
            laari={transaction.eligible_laari}
            className="sm:col-span-2"
          />
          <span className="text-muted-foreground">
            Customer cashback{' '}
            {transaction.lines === undefined || transaction.lines.length === 0
              ? `(${formatRate(transaction.cashback_rate_percent)})`
              : `(${t('creditSplit.perLine')})`}
          </span>
          <MoneyText
            laari={transaction.cashback_laari}
            className="sm:col-span-2"
          />
          <span className="text-muted-foreground">
            Platform fee{' '}
            {transaction.lines === undefined || transaction.lines.length === 0
              ? `(${formatRate(transaction.platform_fee_percent)})`
              : `(${t('creditSplit.perLine')})`}
          </span>
          <MoneyText laari={transaction.fee_laari} className="sm:col-span-2" />
          <span className="text-muted-foreground">You pay</span>
          <MoneyText
            laari={
              transaction.cashback_laari +
              transaction.fee_laari +
              transaction.fee_gst_laari
            }
            className="sm:col-span-2 font-medium"
          />
        </div>

        {transaction.lines !== undefined && transaction.lines.length > 0 && (
          <div className="flex flex-col gap-1.5 pt-1">
            <span className="text-xs font-medium text-muted-foreground">
              {t('creditSplit.resultLinesTitle')}
            </span>
            <ResultLines lines={transaction.lines} categories={categories} />
          </div>
        )}
      </CardContent>
    </Card>
  );
}

/** A fresh one-row split: the whole amount in the default bucket. */
function initialSplitRows(): SplitRow[] {
  return [{ key: 1, category: DEFAULT_BUCKET, amount: '' }];
}

export default function CreditPage() {
  const { t } = useTranslation();
  const { me } = useLayout();
  // PLAN §1 staff roles: rate authority is manager and above. Keying a sale
  // in is staff work, but choosing what it pays is not — the API answers
  // 403 `manager_required` to a staff account that sends
  // `cashback_rate_percent`, so the control is not rendered for one.
  const canSetCustomRate = hasRoleAtLeast(me.role, 'manager');
  const [code, setCode] = useState('');
  const [invoiceNo, setInvoiceNo] = useState('');
  const [eligibleInput, setEligibleInput] = useState('');
  /**
   * True once the cashier types in the total themselves. From then on their
   * figure wins and the split stops writing into the field — clearing it
   * hands control back.
   */
  const [eligibleTouched, setEligibleTouched] = useState(false);
  const [saleInput, setSaleInput] = useState('');
  const [occurredAt, setOccurredAt] = useState(nowLocalValue);
  const [result, setResult] = useState<CreditResult | null>(null);
  const [splitEnabled, setSplitEnabled] = useState(false);
  const [splitRows, setSplitRows] = useState<SplitRow[]>(initialSplitRows);
  const [backdatedConfirmed, setBackdatedConfirmed] = useState(false);
  // The per-sale rate override is opt-in and collapsed: the standing rate
  // is the normal case and must stay a single-glance screen.
  const [overrideOpen, setOverrideOpen] = useState(false);
  const [overrideInput, setOverrideInput] = useState('');

  const rate = useRate();
  const lookup = useCustomerLookup(code);
  const createCredit = useCreateCredit();
  const categoriesQuery = useProductCategories();
  const promotionsQuery = usePromotions();

  const allCategories = useMemo(
    () => categoriesQuery.data ?? [],
    [categoriesQuery.data],
  );
  const activeCategories = useMemo(
    () => allCategories.filter((category) => category.active),
    [allCategories],
  );

  /**
   * The live promotion a MANUAL credit can price under: published, window
   * covering now, merchant-wide (manual credits carry no branch, so
   * branch-scoped promotions never apply). Highest rate wins, mirroring
   * the server's resolution order. Preview only — the server re-resolves
   * at the sale time.
   */
  const livePromo = useMemo(() => {
    const live = (promotionsQuery.data ?? []).filter(
      (promotion) => promotion.is_live && promotion.branch_id === null,
    );
    if (live.length === 0) return null;
    // "Highest rate wins" is a comparison, so it is made in integer bp —
    // "4.99" sorts after "5.00" as text.
    return live.reduce((best, promotion) =>
      percentToBp(promotion.cashback_rate_percent) >
      percentToBp(best.cashback_rate_percent)
        ? promotion
        : best,
    );
  }, [promotionsQuery.data]);

  const typedEligibleLaari = useMemo(
    () => (eligibleInput.trim() === '' ? null : safeParseMvr(eligibleInput)),
    [eligibleInput],
  );

  /**
   * When the sale is itemised, the eligible total IS the sum of the lines, so
   * the cashier should not have to type it twice and then reconcile the two.
   * Leaving the field blank derives it from the split; typing a figure keeps
   * it authoritative and the mismatch check still guards a mistyped line.
   */
  const derivedFromSplitLaari = useMemo(() => {
    if (!splitEnabled) return null;
    // A row still being filled in is skipped, not treated as a failure —
    // "Add line" would otherwise blank the total the cashier just watched
    // appear, until the new row is typed. A row with something UNPARSEABLE
    // in it is a different matter: the sum would be a lie, so there is none.
    const typed = splitRows.filter((row) => row.amount.trim() !== '');
    if (typed.length === 0) return null;
    const amounts = typed.map((row) => safeParseMvr(row.amount));
    if (amounts.some((amount) => amount === null || amount < 0)) return null;
    return amounts.reduce<number>((sum, amount) => sum + (amount as number), 0);
  }, [splitEnabled, splitRows]);

  const eligibleIsDerived = !eligibleTouched && derivedFromSplitLaari !== null;
  const eligibleLaari = eligibleTouched
    ? typedEligibleLaari
    : (derivedFromSplitLaari ?? typedEligibleLaari);

  /**
   * Keep the total field showing the split's sum so the cashier sees the
   * figure being submitted in the box itself, not only in a hint. Plain
   * digits, no separators, so re-parsing it is lossless.
   */
  useEffect(() => {
    if (eligibleTouched) return;
    const next =
      derivedFromSplitLaari === null
        ? ''
        : (derivedFromSplitLaari / 100).toFixed(2);
    setEligibleInput((current) => (current === next ? current : next));
  }, [derivedFromSplitLaari, eligibleTouched]);
  const saleLaari = useMemo(
    () => (saleInput.trim() === '' ? null : safeParseMvr(saleInput)),
    [saleInput],
  );

  const eligibleInvalid =
    eligibleInput.trim() !== '' &&
    (typedEligibleLaari === null || typedEligibleLaari < 0);
  const saleInvalid =
    saleInput.trim() !== '' &&
    (saleLaari === null ||
      (eligibleLaari !== null && saleLaari < eligibleLaari));

  /**
   * PLAN §1: the sale time is OPTIONAL. The field is prefilled with now for
   * the ordinary case, and clearing it means "record this as now" — the key
   * is then omitted from the request entirely, never sent empty.
   */
  const occurredProvided = occurredAt.trim() !== '';
  const occurredEpoch = useMemo(
    () =>
      occurredAt.trim() === ''
        ? Number.NaN
        : Date.parse(toBusinessIso(occurredAt)),
    [occurredAt],
  );
  /** A typed-in time that is not a real instant (partial datetime entry). */
  const occurredUnparseable =
    occurredProvided && !Number.isFinite(occurredEpoch);
  const futureDated =
    Number.isFinite(occurredEpoch) &&
    occurredEpoch > Date.now() + 5 * 60 * 1000;

  /**
   * PLAN §1 "Backdated credits". The server's threshold is the merchant's
   * validation window + 3 grace days; the panel knows only the bounds of
   * that window (0 … the platform default), so it warns across the whole
   * band it could fall in:
   *
   *  - older than default + grace  → certainly backdated;
   *  - older than grace alone      → backdated IF this store shortened its
   *                                  window, which the panel cannot read.
   *
   * Both require the confirmation, because both can produce a credit that
   * can never be reversed; only the wording differs, and it never claims
   * certainty it does not have.
   */
  const ageMs = Number.isFinite(occurredEpoch) ? Date.now() - occurredEpoch : 0;
  const certainlyBackdated =
    Number.isFinite(occurredEpoch) &&
    ageMs > (DEFAULT_VALIDATION_WINDOW_DAYS + BACKDATED_GRACE_DAYS) * DAY_MS;
  const possiblyBackdated =
    !certainlyBackdated &&
    Number.isFinite(occurredEpoch) &&
    ageMs > BACKDATED_GRACE_DAYS * DAY_MS;
  const backdatedWarning = certainlyBackdated || possiblyBackdated;

  const currentRate = rate.data?.current ?? null;

  /**
   * Promo minimum purchase is evaluated against the WHOLE eligible amount
   * (never per line), exactly as the server applies it to the sale.
   */
  const appliedPromo =
    livePromo !== null &&
    eligibleLaari !== null &&
    !eligibleInvalid &&
    (livePromo.min_purchase_laari === null ||
      eligibleLaari >= livePromo.min_purchase_laari)
      ? livePromo
      : null;

  /**
   * Every rate below is read as integer basis points because the screen
   * COMPARES rates (is the override above what is advertised?) and prices
   * an estimate from them. What is shown to the merchant is either the
   * server's own percent string or the exact percent they typed.
   */
  const standingRateBp =
    currentRate === null
      ? null
      : percentToBp(currentRate.cashback_rate_percent);
  const standingFeeBp =
    currentRate === null || currentRate.platform_fee_percent === null
      ? null
      : percentToBp(currentRate.platform_fee_percent);
  const promoRateBp =
    appliedPromo === null
      ? null
      : percentToBp(appliedPromo.cashback_rate_percent);
  const promoFeeBp =
    appliedPromo === null || appliedPromo.platform_fee_percent === null
      ? null
      : percentToBp(appliedPromo.platform_fee_percent);

  /**
   * The rate this sale already earns — the floor a custom rate may not dip
   * below, mirroring CreditRecorder's own max(standing, live promotion).
   * Null only when the store has no standing rate at all, where the server
   * refuses the credit outright.
   */
  const advertisedBp =
    standingRateBp === null && promoRateBp === null
      ? null
      : Math.max(standingRateBp ?? 0, promoRateBp ?? 0);

  const overrideTrimmed = overrideInput.trim();
  const typedOverrideBp =
    overrideTrimmed === '' ? null : parsePercentToBp(overrideTrimmed);
  const overrideError =
    !overrideOpen || overrideTrimmed === ''
      ? null
      : typedOverrideBp === null
        ? t('credit.customRateFormat')
        : typedOverrideBp < MIN_RATE_BP || typedOverrideBp > MAX_RATE_BP
          ? t('credit.customRateRange', {
              min: formatBp(MIN_RATE_BP),
              max: formatBp(MAX_RATE_BP),
            })
          : advertisedBp !== null && typedOverrideBp < advertisedBp
            ? t('credit.customRateTooLow', { rate: formatBp(advertisedBp) })
            : null;
  /** The override actually in force: opened, typed, and legal. */
  const overrideBp =
    canSetCustomRate && overrideOpen && overrideError === null
      ? typedOverrideBp
      : null;

  /**
   * The sale's BASE rate: the override when one is set, the standing rate
   * otherwise — exactly the substitution the server makes. The fee follows
   * the applied rate; for an override the panel estimates it from the §4
   * static bands, since the server has only quoted the fee for the standing
   * rate.
   */
  const baseRateBp = overrideBp ?? standingRateBp;
  const baseFeeBp =
    overrideBp !== null ? estimateFeeBpFor(overrideBp) : standingFeeBp;

  const splitActive = splitEnabled && activeCategories.length > 0;

  const splitAnalysis = useMemo(
    () =>
      analyzeSplit(
        splitRows,
        activeCategories,
        {
          rateBp: baseRateBp,
          feeBp: baseFeeBp,
          fromOverride: overrideBp !== null,
        },
        { rateBp: promoRateBp, feeBp: promoFeeBp },
        eligibleLaari !== null && !eligibleInvalid ? eligibleLaari : null,
      ),
    [
      splitRows,
      activeCategories,
      baseRateBp,
      baseFeeBp,
      overrideBp,
      promoRateBp,
      promoFeeBp,
      eligibleLaari,
      eligibleInvalid,
    ],
  );

  // No fee preview when the applied rate has no priced fee (a stranded
  // legacy rate — the server refuses credits in that state anyway).
  const preview =
    eligibleLaari !== null &&
    !eligibleInvalid &&
    baseRateBp !== null &&
    baseFeeBp !== null
      ? {
          cashback: estimateLaariAtBp(eligibleLaari, baseRateBp),
          fee: estimateLaariAtBp(eligibleLaari, baseFeeBp),
        }
      : null;

  const canSubmit =
    lookup.data?.valid === true &&
    invoiceNo.trim() !== '' &&
    eligibleLaari !== null &&
    !eligibleInvalid &&
    !saleInvalid &&
    // An empty sale time is valid — it means now.
    !occurredUnparseable &&
    !futureDated &&
    // A half-typed custom rate must never fall back to the standing one
    // silently: fix it or close the control.
    overrideError === null &&
    // An irreversible, immediately-payable credit is never one click away.
    (!backdatedWarning || backdatedConfirmed) &&
    (!splitActive || splitAnalysis.submittable) &&
    !createCredit.isPending;

  // A new mutate() clears any previous error state itself.
  const resetForm = () => {
    setCode('');
    setInvoiceNo('');
    setEligibleInput('');
    setSaleInput('');
    setOccurredAt(nowLocalValue());
    setSplitRows(initialSplitRows());
    setBackdatedConfirmed(false);
    setOverrideInput('');
    setOverrideOpen(false);
  };

  /** Changing the sale time re-opens the question, so the tick resets. */
  const changeOccurredAt = (value: string) => {
    setOccurredAt(value);
    setBackdatedConfirmed(false);
  };

  const submit = () => {
    if (!canSubmit || lookup.data?.valid !== true) return;
    const maskedName = lookup.data.masked_name;
    createCredit.mutate(
      {
        customer_code: code,
        invoice_no: invoiceNo.trim(),
        eligible_amount: eligibleLaari as number,
        ...(saleLaari !== null && !saleInvalid
          ? { sale_amount: saleLaari }
          : {}),
        // PLAN §1: omitted means NOW. An empty field sends no key at all —
        // never an empty string, which the server would refuse.
        ...(occurredProvided ? { occurred_at: toBusinessIso(occurredAt) } : {}),
        // PLAN §1 per-sale override: the wire wants a 2-decimal percent, and
        // the panel has the exact bp it validated. Absent unless in force.
        ...(overrideBp !== null
          ? { cashback_rate_percent: bpToPercentString(overrideBp) }
          : {}),
        // The split is OPTIONAL — with the toggle off the request stays
        // byte-identical to the single-rate path.
        ...(splitActive
          ? {
              lines: splitRows.map((row) => ({
                category: row.category === DEFAULT_BUCKET ? null : row.category,
                amount_laari: parseMvrToLaari(row.amount.trim()),
              })),
            }
          : {}),
      },
      {
        onSuccess: (response) => {
          setResult({ transaction: response.data, maskedName });
          resetForm();
        },
      },
    );
  };

  const submitError = createCredit.error;
  const duplicateInvoice =
    submitError instanceof ApiError && submitError.status === 409;
  const suspendedMerchant =
    submitError instanceof ApiError &&
    submitError.status === 422 &&
    apiErrorMessage(submitError, '').includes('active merchant');
  /**
   * The two refusals a custom rate can earn (PLAN §1). Both are answered in
   * plain words next to the control that caused them — never as the raw
   * server sentence, which names the wire field.
   */
  const rateBelowAdvertised = isRateBelowAdvertised(submitError);
  const rateNotPriced = isRateNotPriced(submitError);
  const refusedAgainst = advertisedRatePercent(submitError);

  return (
    <div className="container">
      <Toolbar>
        <ToolbarHeading>
          <ToolbarPageTitle>Credit customer</ToolbarPageTitle>
          <ToolbarDescription>
            Record a sale by hand and credit the customer&apos;s cashback
          </ToolbarDescription>
        </ToolbarHeading>
      </Toolbar>

      {result && (
        <ResultCard
          result={result}
          categories={allCategories}
          onReset={() => setResult(null)}
        />
      )}

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-5 pb-7.5 items-start">
        <Card className="lg:col-span-2">
          <CardHeader>
            <CardTitle>New manual credit</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-5">
            <div className="flex flex-col gap-2.5">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <Label htmlFor="customer-code">Customer code</Label>
                {/* Renders only where BarcodeDetector + a camera exist;
                    typing the code stays the universal path. */}
                <QrScanButton
                  onCode={(scanned) => {
                    setCode(scanned);
                    toast.success(t('credit.scanned', { code: scanned }));
                  }}
                />
              </div>
              <InputOTP
                id="customer-code"
                maxLength={6}
                pattern={REGEXP_ONLY_DIGITS}
                value={code}
                onChange={setCode}
                autoFocus
              >
                <InputOTPGroup>
                  {[0, 1, 2, 3, 4, 5].map((index) => (
                    <InputOTPSlot key={index} index={index} />
                  ))}
                </InputOTPGroup>
              </InputOTP>
              <LookupCard code={code} />
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
              <div className="flex flex-col gap-2.5">
                <Label htmlFor="invoice-no">Invoice number</Label>
                <Input
                  id="invoice-no"
                  value={invoiceNo}
                  maxLength={64}
                  onChange={(event) => setInvoiceNo(event.target.value)}
                  placeholder="INV-1001"
                />
                <p className="text-xs text-muted-foreground">
                  Required — one credit per invoice, exactly as printed on the
                  receipt.
                </p>
              </div>

              <div className="flex flex-col gap-2.5">
                <Label htmlFor="occurred-at">
                  Sale date &amp; time{' '}
                  <span className="text-muted-foreground font-normal">
                    ({t('common.optional')})
                  </span>
                </Label>
                <Input
                  id="occurred-at"
                  type="datetime-local"
                  value={occurredAt}
                  max={nowLocalValue()}
                  onChange={(event) => changeOccurredAt(event.target.value)}
                />
                {futureDated ? (
                  <p className="text-xs text-destructive">
                    The sale time cannot be in the future.
                  </p>
                ) : !occurredProvided ? (
                  <p className="text-xs text-muted-foreground">
                    {t('credit.occurredAtNow')}{' '}
                    <button
                      type="button"
                      className="underline underline-offset-2"
                      onClick={() => changeOccurredAt(nowLocalValue())}
                    >
                      {t('credit.occurredAtSetNow')}
                    </button>
                  </p>
                ) : (
                  <p className="text-xs text-muted-foreground">
                    {t('credit.occurredAtHint')}
                  </p>
                )}
              </div>

              <div className="flex flex-col gap-2.5">
                <Label htmlFor="eligible-amount">Eligible amount</Label>
                <InputGroup>
                  <InputAddon>MVR</InputAddon>
                  <Input
                    id="eligible-amount"
                    inputMode="decimal"
                    value={eligibleInput}
                    onChange={(event) => {
                      setEligibleInput(event.target.value);
                      setEligibleTouched(event.target.value.trim() !== '');
                    }}
                    placeholder="0.00"
                    aria-invalid={eligibleInvalid}
                  />
                </InputGroup>
                {eligibleInvalid ? (
                  <p className="text-xs text-destructive">
                    Enter a valid amount, e.g. 1,250.00.
                  </p>
                ) : eligibleIsDerived ? (
                  /* Itemised sale, total left blank: say out loud what is
                     being sent rather than submitting a figure the cashier
                     never saw. */
                  <p className="text-xs text-muted-foreground">
                    Adding up to{' '}
                    <MoneyText
                      laari={eligibleLaari as number}
                      className="font-medium text-mono"
                    />{' '}
                    from the categories below.
                  </p>
                ) : (
                  <p className="text-xs text-muted-foreground">
                    The part of the bill cashback is computed on, per your
                    agreement. Leave blank when splitting by category and it
                    adds up for you.
                  </p>
                )}
              </div>

              <div className="flex flex-col gap-2.5">
                <Label htmlFor="sale-amount">
                  Full sale amount{' '}
                  <span className="text-muted-foreground font-normal">
                    ({t('common.optional')})
                  </span>
                </Label>
                <InputGroup>
                  <InputAddon>MVR</InputAddon>
                  <Input
                    id="sale-amount"
                    inputMode="decimal"
                    value={saleInput}
                    onChange={(event) => setSaleInput(event.target.value)}
                    placeholder="0.00"
                    aria-invalid={saleInvalid}
                  />
                </InputGroup>
                {saleInvalid ? (
                  <p className="text-xs text-destructive">
                    Must be a valid amount, at least the eligible amount.
                  </p>
                ) : (
                  <p className="text-xs text-muted-foreground">
                    The whole invoice total — reference only, never used in
                    computation.
                  </p>
                )}
              </div>
            </div>

            {/* PLAN §1 "Per-sale rate override": a one-off cashback rate for
                THIS sale. Collapsed by default — the standing rate is the
                normal case — and it may only go UP: the rate the storefront
                advertises is a promise, and the customer is told the higher
                figure. The server re-checks both bounds. Manager and above
                only, like every other rate control (staff key the sale in at
                the store's own terms). */}
            {canSetCustomRate ? (
              <div className="flex flex-col gap-4">
                <div className="flex items-center gap-2.5">
                  <Switch
                    id="custom-rate"
                    size="sm"
                    checked={overrideOpen}
                    onCheckedChange={(checked) => {
                      setOverrideOpen(checked);
                      if (!checked) setOverrideInput('');
                    }}
                  />
                  <Label htmlFor="custom-rate">
                    {t('credit.customRateToggle')}
                  </Label>
                </div>
                {overrideOpen ? (
                  <div className="flex flex-col gap-2.5">
                    <Label htmlFor="custom-rate-value">
                      {t('credit.customRateLabel')}
                    </Label>
                    <InputGroup className="w-44">
                      <Input
                        id="custom-rate-value"
                        inputMode="decimal"
                        dir="ltr"
                        placeholder={
                          advertisedBp === null
                            ? '0.00'
                            : formatBp(advertisedBp).replace('%', '')
                        }
                        value={overrideInput}
                        aria-invalid={overrideError !== null}
                        onChange={(event) =>
                          setOverrideInput(event.target.value)
                        }
                      />
                      <InputAddon>%</InputAddon>
                    </InputGroup>
                    {overrideError !== null ? (
                      <p className="text-xs text-destructive">
                        {overrideError}
                      </p>
                    ) : (
                      <p className="text-xs text-muted-foreground">
                        {advertisedBp === null
                          ? t('credit.customRateHintNoRate')
                          : t('credit.customRateHint', {
                              rate: formatBp(advertisedBp),
                            })}
                      </p>
                    )}
                  </div>
                ) : (
                  <p className="text-xs text-muted-foreground">
                    {t('credit.customRateToggleHint')}
                  </p>
                )}
              </div>
            ) : null}

            {activeCategories.length > 0 && (
              <div className="flex flex-col gap-4">
                <div className="flex items-center gap-2.5">
                  <Switch
                    id="split-by-category"
                    size="sm"
                    checked={splitEnabled}
                    onCheckedChange={setSplitEnabled}
                  />
                  <Label htmlFor="split-by-category">
                    {t('creditSplit.toggleLabel')}
                  </Label>
                </div>
                {splitEnabled ? (
                  <>
                    <p className="text-xs text-muted-foreground">
                      {t('creditSplit.editorHint')}
                    </p>
                    <SplitEditor
                      rows={splitRows}
                      onRowsChange={setSplitRows}
                      categories={activeCategories}
                      analysis={splitAnalysis}
                      eligibleLaari={
                        eligibleLaari !== null && !eligibleInvalid
                          ? eligibleLaari
                          : null
                      }
                    />
                  </>
                ) : (
                  <p className="text-xs text-muted-foreground">
                    {t('creditSplit.toggleHint')}
                  </p>
                )}
              </div>
            )}

            {/* PLAN §1 "Backdated credits": no admin approval, payable
                immediately, merchant-irreversible. The warning replaces the
                old "Manfaa reviews it first" copy, which is no longer true,
                and the tick is required before the credit can be recorded. */}
            {backdatedWarning && (
              <Alert
                variant={certainlyBackdated ? 'destructive' : 'warning'}
                appearance="light"
              >
                <AlertIcon>
                  <TriangleAlert />
                </AlertIcon>
                <AlertContent>
                  <AlertTitle>
                    {t(
                      certainlyBackdated
                        ? 'credit.backdatedTitle'
                        : 'credit.backdatedMaybeTitle',
                    )}
                  </AlertTitle>
                  <AlertDescription>
                    {t(
                      certainlyBackdated
                        ? 'credit.backdatedBody'
                        : 'credit.backdatedMaybeBody',
                    )}
                  </AlertDescription>
                  <div className="mt-3 flex items-start gap-2.5">
                    <Checkbox
                      id="backdated-confirm"
                      checked={backdatedConfirmed}
                      onCheckedChange={(checked) =>
                        setBackdatedConfirmed(checked === true)
                      }
                      className="mt-0.5"
                    />
                    <Label
                      htmlFor="backdated-confirm"
                      className="text-sm font-normal"
                    >
                      {t('credit.backdatedConfirm')}
                    </Label>
                  </div>
                </AlertContent>
              </Alert>
            )}

            {submitError && (
              <Alert variant="destructive" appearance="light">
                <AlertIcon>
                  <TriangleAlert />
                </AlertIcon>
                <AlertContent>
                  <AlertTitle>
                    {duplicateInvoice
                      ? 'This invoice is already credited.'
                      : suspendedMerchant
                        ? 'Your store is suspended — new cashback is paused.'
                        : rateBelowAdvertised
                          ? t('credit.customRateRefusedTitle')
                          : rateNotPriced
                            ? t('credit.customRateNotPricedTitle')
                            : apiErrorMessage(
                                submitError,
                                'Could not record the credit.',
                              )}
                  </AlertTitle>
                  {rateBelowAdvertised && (
                    <AlertDescription>
                      {refusedAgainst === null
                        ? t('credit.customRateRefusedBodyNoRate')
                        : t('credit.customRateRefusedBody', {
                            rate: formatRate(refusedAgainst),
                          })}
                    </AlertDescription>
                  )}
                  {rateNotPriced && (
                    <AlertDescription>
                      {rateNotPricedMessage(submitError)}
                    </AlertDescription>
                  )}
                  {duplicateInvoice && (
                    <AlertDescription>
                      Each invoice can be credited once. If this is a different
                      sale, check the invoice number on the receipt.
                    </AlertDescription>
                  )}
                  {suspendedMerchant && (
                    <AlertDescription>
                      Settle your outstanding balance to resume crediting
                      customers, or contact Manfaa if you believe this is wrong.
                    </AlertDescription>
                  )}
                </AlertContent>
              </Alert>
            )}

            <div className="flex flex-wrap items-center justify-end gap-3">
              {backdatedWarning && !backdatedConfirmed && (
                <span className="text-xs text-muted-foreground">
                  {t('credit.backdatedConfirmRequired')}
                </span>
              )}
              <Button disabled={!canSubmit} onClick={submit}>
                {createCredit.isPending ? (
                  <LoaderCircle className="animate-spin" />
                ) : (
                  <HandCoins />
                )}
                Credit customer
              </Button>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Cost preview</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-3 text-sm">
            {rate.error ? (
              <span className="text-muted-foreground">
                Your current rate is unavailable right now — the credit still
                uses the correct rate on the server.
              </span>
            ) : !rate.data ? (
              <Skeleton className="h-24 rounded-md" />
            ) : !currentRate ? (
              <span className="text-muted-foreground">
                No cashback rate is in effect yet — contact Manfaa before
                crediting customers.
              </span>
            ) : splitActive ? (
              <>
                <div className="flex justify-between gap-3">
                  <span className="text-muted-foreground">
                    {t('creditSplit.previewCashback')}
                  </span>
                  {splitAnalysis.cashbackTotal !== null ? (
                    <MoneyText laari={splitAnalysis.cashbackTotal} />
                  ) : (
                    <span className="text-muted-foreground">—</span>
                  )}
                </div>
                <div className="flex justify-between gap-3">
                  <span className="text-muted-foreground">
                    {t('creditSplit.previewFee')}
                  </span>
                  {splitAnalysis.feeTotal !== null ? (
                    <MoneyText laari={splitAnalysis.feeTotal} />
                  ) : (
                    <span className="text-muted-foreground">—</span>
                  )}
                </div>
                <div className="flex justify-between gap-3 border-t border-border pt-2 font-medium">
                  <span>{t('creditSplit.previewYouPay')}</span>
                  {splitAnalysis.cashbackTotal !== null &&
                  splitAnalysis.feeTotal !== null ? (
                    <MoneyText
                      laari={
                        splitAnalysis.cashbackTotal + splitAnalysis.feeTotal
                      }
                    />
                  ) : (
                    <span className="text-muted-foreground">—</span>
                  )}
                </div>
                <p className="text-xs text-muted-foreground">
                  {t('creditSplit.previewNote')}
                </p>
              </>
            ) : (
              <>
                <div className="flex justify-between gap-3">
                  <span className="text-muted-foreground">
                    Customer cashback (
                    {overrideBp !== null
                      ? formatBp(overrideBp)
                      : formatRate(currentRate.cashback_rate_percent)}
                    )
                  </span>
                  {preview ? (
                    <MoneyText laari={preview.cashback} />
                  ) : (
                    <span className="text-muted-foreground">—</span>
                  )}
                </div>
                <div className="flex justify-between gap-3">
                  <span className="text-muted-foreground">
                    Platform fee (
                    {overrideBp !== null
                      ? formatBp(estimateFeeBpFor(overrideBp))
                      : formatRateOrDash(currentRate.platform_fee_percent)}
                    )
                  </span>
                  {preview ? (
                    <MoneyText laari={preview.fee} />
                  ) : (
                    <span className="text-muted-foreground">—</span>
                  )}
                </div>
                <div className="flex justify-between gap-3 border-t border-border pt-2 font-medium">
                  <span>
                    You pay (
                    {overrideBp !== null
                      ? formatBp(overrideBp + estimateFeeBpFor(overrideBp))
                      : formatRateOrDash(currentRate.all_in_percent)}
                    )
                  </span>
                  {preview ? (
                    <MoneyText laari={preview.cashback + preview.fee} />
                  ) : (
                    <span className="text-muted-foreground">—</span>
                  )}
                </div>
                <p className="text-xs text-muted-foreground">
                  {overrideBp === null
                    ? 'Estimate — final amounts use the rate at the sale time.'
                    : t('credit.customRatePreviewNote')}
                </p>
              </>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
