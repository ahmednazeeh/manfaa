'use client';

import { useMemo, useState } from 'react';
import {
  ApiError,
  parseMvrToLaari,
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
import { estimateLaariAtBp, formatBp } from '@/lib/estimate';
import {
  apiErrorMessage,
  useCreateCredit,
  useCustomerLookup,
  useProductCategories,
  usePromotions,
  useRate,
} from '@/lib/queries';
import {
  Alert,
  AlertContent,
  AlertDescription,
  AlertIcon,
  AlertTitle,
} from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input, InputAddon, InputGroup } from '@/components/ui/input';
import {
  InputOTP,
  InputOTPGroup,
  InputOTPSlot,
} from '@/components/ui/input-otp';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';
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
import { TransactionStateBadge } from '@/components/app/state-badge';

/**
 * The counter screen (§10, original-spec §8 manual credit path): a staff
 * member keys in a sale by hand. The server owns every rule — rate frozen
 * at occurred_at, ceiling rounding, below-minimum zeroing, stale-timestamp
 * hold, duplicate-invoice rejection. This screen confirms the customer by
 * masked name first (§11 phone-recycling control) and previews the cost as
 * an ESTIMATE at the current rate only.
 */

/** Maldives is UTC+05:00 year-round — occurred_at is sent with it explicit. */
const BUSINESS_UTC_OFFSET = '+05:00';
const BUSINESS_OFFSET_MS = 5 * 60 * 60 * 1000;

/**
 * §1 default refund/validation window. The merchant's own window may differ
 * (server-side setting); the note this drives is a heads-up, and the server
 * decides authoritatively what lands on_hold (window + 3 grace days).
 */
const DEFAULT_VALIDATION_WINDOW_DAYS = 3;

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
              <AlertTitle>
                Backdated entry — Manfaa reviews it first.
              </AlertTitle>
              <AlertDescription>
                The sale is recorded and on hold. It counts for the customer
                once the review approves it; nothing else is needed from you.
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
            {transaction.reason_code && (
              <span className="ms-2 text-xs text-muted-foreground">
                {transaction.reason_code}
              </span>
            )}
          </span>
          <span className="text-muted-foreground">Eligible amount</span>
          <MoneyText
            laari={transaction.eligible_laari}
            className="sm:col-span-2"
          />
          <span className="text-muted-foreground">
            Customer cashback{' '}
            {transaction.lines === undefined || transaction.lines.length === 0
              ? `(${formatBp(transaction.rate_bp)})`
              : `(${t('creditSplit.perLine')})`}
          </span>
          <MoneyText
            laari={transaction.cashback_laari}
            className="sm:col-span-2"
          />
          <span className="text-muted-foreground">
            Platform fee{' '}
            {transaction.lines === undefined || transaction.lines.length === 0
              ? `(${formatBp(transaction.fee_bp)})`
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
  const [code, setCode] = useState('');
  const [invoiceNo, setInvoiceNo] = useState('');
  const [eligibleInput, setEligibleInput] = useState('');
  const [saleInput, setSaleInput] = useState('');
  const [occurredAt, setOccurredAt] = useState(nowLocalValue);
  const [result, setResult] = useState<CreditResult | null>(null);
  const [splitEnabled, setSplitEnabled] = useState(false);
  const [splitRows, setSplitRows] = useState<SplitRow[]>(initialSplitRows);

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
    return live.reduce((best, promotion) =>
      promotion.rate_bp > best.rate_bp ? promotion : best,
    );
  }, [promotionsQuery.data]);

  const eligibleLaari = useMemo(
    () => (eligibleInput.trim() === '' ? null : safeParseMvr(eligibleInput)),
    [eligibleInput],
  );
  const saleLaari = useMemo(
    () => (saleInput.trim() === '' ? null : safeParseMvr(saleInput)),
    [saleInput],
  );

  const eligibleInvalid =
    eligibleInput.trim() !== '' &&
    (eligibleLaari === null || eligibleLaari < 0);
  const saleInvalid =
    saleInput.trim() !== '' &&
    (saleLaari === null ||
      (eligibleLaari !== null && saleLaari < eligibleLaari));

  const occurredEpoch = useMemo(
    () => (occurredAt ? Date.parse(toBusinessIso(occurredAt)) : Number.NaN),
    [occurredAt],
  );
  const futureDated =
    Number.isFinite(occurredEpoch) &&
    occurredEpoch > Date.now() + 5 * 60 * 1000;
  const backdated =
    Number.isFinite(occurredEpoch) &&
    Date.now() - occurredEpoch >
      DEFAULT_VALIDATION_WINDOW_DAYS * 24 * 60 * 60 * 1000;

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

  const splitActive = splitEnabled && activeCategories.length > 0;

  const splitAnalysis = useMemo(
    () =>
      analyzeSplit(
        splitRows,
        activeCategories,
        currentRate?.rate_bp ?? null,
        currentRate?.fee_bp ?? null,
        appliedPromo?.rate_bp ?? null,
        appliedPromo?.fee_bp ?? null,
        eligibleLaari !== null && !eligibleInvalid ? eligibleLaari : null,
      ),
    [
      splitRows,
      activeCategories,
      currentRate,
      appliedPromo,
      eligibleLaari,
      eligibleInvalid,
    ],
  );

  // No fee preview when the standing rate has no priced fee (stranded
  // legacy rate — the server refuses credits in that state anyway).
  const preview =
    eligibleLaari !== null &&
    !eligibleInvalid &&
    currentRate &&
    currentRate.fee_bp !== null
      ? {
          cashback: estimateLaariAtBp(eligibleLaari, currentRate.rate_bp),
          fee: estimateLaariAtBp(eligibleLaari, currentRate.fee_bp),
        }
      : null;

  const canSubmit =
    lookup.data?.valid === true &&
    invoiceNo.trim() !== '' &&
    eligibleLaari !== null &&
    !eligibleInvalid &&
    !saleInvalid &&
    occurredAt !== '' &&
    Number.isFinite(occurredEpoch) &&
    !futureDated &&
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
        occurred_at: toBusinessIso(occurredAt),
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
              <Label htmlFor="customer-code">Customer code</Label>
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
                <Label htmlFor="occurred-at">Sale date &amp; time</Label>
                <Input
                  id="occurred-at"
                  type="datetime-local"
                  value={occurredAt}
                  max={nowLocalValue()}
                  onChange={(event) => setOccurredAt(event.target.value)}
                />
                {futureDated && (
                  <p className="text-xs text-destructive">
                    The sale time cannot be in the future.
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
                    onChange={(event) => setEligibleInput(event.target.value)}
                    placeholder="0.00"
                    aria-invalid={eligibleInvalid}
                  />
                </InputGroup>
                {eligibleInvalid ? (
                  <p className="text-xs text-destructive">
                    Enter a valid amount, e.g. 1,250.00.
                  </p>
                ) : (
                  <p className="text-xs text-muted-foreground">
                    The part of the bill cashback is computed on, per your
                    agreement.
                  </p>
                )}
              </div>

              <div className="flex flex-col gap-2.5">
                <Label htmlFor="sale-amount">
                  Full sale amount{' '}
                  <span className="text-muted-foreground font-normal">
                    (optional)
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

            {backdated && (
              <Alert variant="warning" appearance="light">
                <AlertIcon>
                  <Clock4 />
                </AlertIcon>
                <AlertContent>
                  <AlertTitle>Backdated entry</AlertTitle>
                  <AlertDescription>
                    This sale is older than the validation window — entries this
                    old are reviewed by Manfaa before they count.
                  </AlertDescription>
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
                        : apiErrorMessage(
                            submitError,
                            'Could not record the credit.',
                          )}
                  </AlertTitle>
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

            <div className="flex justify-end">
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
                    Customer cashback ({formatBp(currentRate.rate_bp)})
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
                    {currentRate.fee_bp === null
                      ? '—'
                      : formatBp(currentRate.fee_bp)}
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
                    {currentRate.all_in_bp === null
                      ? '—'
                      : formatBp(currentRate.all_in_bp)}
                    )
                  </span>
                  {preview ? (
                    <MoneyText laari={preview.cashback + preview.fee} />
                  ) : (
                    <span className="text-muted-foreground">—</span>
                  )}
                </div>
                <p className="text-xs text-muted-foreground">
                  Estimate — final amounts use the rate at the sale time.
                </p>
              </>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
