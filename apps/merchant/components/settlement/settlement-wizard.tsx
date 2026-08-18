'use client';

import { useMemo, useState } from 'react';
import Link from 'next/link';
import { formatLaari } from '@manfaa/api-client';
import { MoneyText } from '@manfaa/ui';
import { format } from 'date-fns';
import {
  Check,
  CircleCheck,
  HandCoins,
  LoaderCircle,
  ShieldCheck,
  TriangleAlert,
  Wallet as WalletIcon,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import type {
  MerchantSettlement,
  ReceiptSubmission,
  SettlementSelection,
} from '@/lib/api';
import {
  apiErrorMessage,
  isSelectionRefusal,
  useOutstanding,
  useSettlementPreview,
  useSubmitSettlementReceipt,
  useWallet,
  useWalletSettleSelection,
} from '@/lib/queries';
import { cn } from '@/lib/utils';
import { Alert, AlertIcon, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
  Card,
  CardContent,
  CardFooter,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import {
  Toolbar,
  ToolbarDescription,
  ToolbarHeading,
  ToolbarPageTitle,
} from '@/components/app-layout/toolbar';
import {
  EmptyBlock,
  ErrorBlock,
  LoadingBlock,
} from '@/components/app/async-states';
import { PaymentInstructions } from '@/components/settlement/payment-instructions';
import {
  DiscountedFeeAmount,
  isDiscountDisabled,
  PromptDiscountNotice,
  PromptDiscountRow,
} from '@/components/settlement/prompt-discount';
import { ReceiptForm } from '@/components/settlement/receipt-form';
import { TransactionPicker } from '@/components/settlement/transaction-picker';

/**
 * The receipt-first settlement wizard (PLAN §1 "Settlement flow"), which
 * REPLACES the old create-a-draft builder:
 *
 *   1. choose transactions (or settle everything outstanding)
 *   2. see the amount due, the platform's bank account and the reference —
 *      then transfer at your own bank
 *   3. upload the slip + bank reference and submit
 *
 * Submitting is the only act that creates a settlement, and it carries the
 * receipt: there is no step, button or URL in this panel that produces a
 * settlement without one. The batch lands in payment_review and an admin
 * verifies the slip.
 *
 * Step 2 also offers the §7 wallet alternative (same path, different funding
 * source) and is the only route for a batch that credit adjustments have
 * netted to zero — there is no transfer to evidence, so no receipt can
 * honestly claim one.
 *
 * **Where the money comes from.** One endpoint prices everything: the preview.
 * Step 1 asks it for the whole board — every eligible row, the age buckets
 * behind the filter chips, and the PLAN §1 discount verdict for settling the
 * lot — and the picker adds up the stored per-row integers as boxes are
 * ticked, so a click costs nothing. The moment it matters, the figure shown is
 * the one the server priced for the selection, and the discount is whatever
 * the server granted — never a percentage this panel applied.
 *
 * **Settling everything is a MODE, not a list.** Whenever the selection covers
 * the whole board the wizard sends `settle_all` rather than the ids: a sale
 * that becomes payable between here and submit then joins the batch, instead
 * of leaving one behind and silently costing the merchant the discount.
 */

const STEPS = ['select', 'transfer'] as const;

/** The race-proof "everything outstanding" selection. */
const SETTLE_ALL: SettlementSelection = { settleAll: true };

export function SettlementWizard() {
  const { t } = useTranslation();
  const [step, setStep] = useState(0);
  const [mode, setMode] = useState<'all' | 'pick'>('all');
  const [selectedIds, setSelectedIds] = useState<number[]>([]);
  const [created, setCreated] = useState<MerchantSettlement | null>(null);
  // Which platform account the merchant says they paid. Null until they pick
  // or the default lands below; the server records nothing rather than
  // guessing, so an unanswered choice is honest instead of wrong.
  const [destinationId, setDestinationId] = useState<number | null>(null);

  const outstanding = useOutstanding();
  const wallet = useWallet();
  const submitReceipt = useSubmitSettlementReceipt();
  const walletSettle = useWalletSettleSelection();

  const selection: SettlementSelection | null = useMemo(() => {
    if (mode === 'all') return SETTLE_ALL;
    return selectedIds.length > 0 ? { transactionIds: selectedIds } : null;
  }, [mode, selectedIds]);

  // The picker's catalogue: every eligible row, the preset buckets and the
  // discount verdict for settling EVERYTHING. Claims nothing (§ preview is
  // reservation-free), so it is safe to hold open while the merchant picks.
  const catalogue = useSettlementPreview(
    SETTLE_ALL,
    step === 0 && created === null,
  );

  // Priced from step 2 onwards and kept for step 3, so the amount the
  // merchant was told to transfer is the amount the receipt form prefills.
  const preview = useSettlementPreview(
    selection,
    step >= 1 && created === null,
  );

  const rows = useMemo(
    () => catalogue.data?.transactions ?? [],
    [catalogue.data],
  );
  const selectedIdSet = useMemo(
    () => new Set(mode === 'all' ? rows.map((row) => row.id) : selectedIds),
    [mode, rows, selectedIds],
  );
  const selectedRows = rows.filter((row) => selectedIdSet.has(row.id));

  // The live running total: a SUM of the server's per-row `due_laari`
  // integers, nothing more. No rate is applied, nothing is divided, and the
  // figure the merchant actually transfers still comes from the priced
  // preview on the next step.
  const selectedTotalLaari = selectedRows.reduce(
    (total, row) => total + row.due_laari,
    0,
  );

  const selectEverything = () => {
    setMode('all');
    setSelectedIds([]);
  };

  const applySelection = (ids: number[]) => {
    const unique = Array.from(new Set(ids));
    if (rows.length > 0 && unique.length === rows.length) {
      selectEverything();
      return;
    }
    setMode('pick');
    setSelectedIds(unique);
  };

  const canContinue = selection !== null && selectedRows.length > 0;

  // ---------------------------------------------------------------------
  // Done. Either the receipt landed the batch in payment_review — "Manfaa is
  // verifying your transfer" — or the wallet route settled it outright,
  // which is not something anybody is verifying and must not say so.
  // ---------------------------------------------------------------------
  if (created !== null) {
    const settledOutright = created.merchant_status.code === 'settled';
    return (
      <div className="container">
        <Toolbar>
          <ToolbarHeading>
            <ToolbarPageTitle>{t('settlement.title')}</ToolbarPageTitle>
          </ToolbarHeading>
        </Toolbar>
        <Card className="mb-7.5 max-w-2xl">
          <CardContent className="flex flex-col items-start gap-4 p-7">
            <div className="flex items-center gap-3">
              <span className="flex size-10 items-center justify-center rounded-full bg-green-500/15">
                {settledOutright ? (
                  <CircleCheck className="size-5 text-green-600" />
                ) : (
                  <ShieldCheck className="size-5 text-green-600" />
                )}
              </span>
              <h2 className="text-lg font-semibold">
                {t(
                  settledOutright
                    ? 'settlement.settledTitle'
                    : 'settlement.successTitle',
                )}
              </h2>
            </div>
            <p className="text-sm text-secondary-foreground">
              {t(
                settledOutright
                  ? 'settlement.settledBody'
                  : 'settlement.successBody',
                { reference: created.reference },
              )}
            </p>
            {created.discount_laari > 0 && (
              <div className="flex flex-wrap items-center gap-1.5 text-sm text-primary">
                <span>{t('settlement.discountSaved')}</span>
                <MoneyText laari={created.discount_laari} />
              </div>
            )}
            <div className="flex flex-wrap gap-3">
              <Button asChild>
                <Link href={`/settlements/${created.id}`}>
                  {t('settlement.viewSettlement')}
                </Link>
              </Button>
              <Button asChild variant="outline">
                <Link href="/settlements">{t('settlement.backToList')}</Link>
              </Button>
            </div>
          </CardContent>
        </Card>
      </div>
    );
  }

  return (
    <div className="container">
      <Toolbar>
        <ToolbarHeading>
          <ToolbarPageTitle>{t('settlement.title')}</ToolbarPageTitle>
          <ToolbarDescription>
            {outstanding.data
              ? t('settlement.outstandingTotal', {
                  count: outstanding.data.total.count,
                  amount: outstanding.data.total.payable_mvr,
                })
              : t('settlement.subtitle')}
          </ToolbarDescription>
        </ToolbarHeading>
      </Toolbar>

      <div className="mb-5">
        <StepIndicator current={step} />
      </div>

      {step === 0 && (
        <Card className="mb-7.5">
          <CardHeader>
            <CardTitle>{t('settlement.selectTitle')}</CardTitle>
          </CardHeader>

          {catalogue.error ? (
            // The domain's "nothing to settle" refusal is not an error the
            // merchant caused — it is an empty board.
            isSelectionRefusal(catalogue.error) ? (
              <EmptyBlock>{t('settlement.emptyPayable')}</EmptyBlock>
            ) : (
              <ErrorBlock
                error={catalogue.error}
                fallback={t('settlement.previewFailed')}
              />
            )
          ) : !catalogue.data ? (
            <LoadingBlock lines={6} />
          ) : (
            <>
              <CardContent className="flex flex-col gap-5">
                <p className="text-sm text-secondary-foreground">
                  {t('settlement.selectLead')}
                </p>
                <PromptDiscountNotice
                  discount={catalogue.data.discount}
                  // The catalogue prices the WHOLE board: it describes the
                  // selection only while the selection is the whole board.
                  variant={mode === 'all' ? 'priced' : 'whole'}
                  onSettleEverything={
                    mode === 'all' ? undefined : selectEverything
                  }
                />
              </CardContent>

              <TransactionPicker
                rows={rows}
                buckets={catalogue.data.buckets}
                selectedIds={Array.from(selectedIdSet)}
                discountMaxAgeDays={
                  isDiscountDisabled(catalogue.data.discount.rate_percent)
                    ? 0
                    : catalogue.data.discount.max_age_days
                }
                onSelect={applySelection}
                onSelectEverything={selectEverything}
              />

              <CardFooter className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-col gap-0.5">
                  <span className="text-xs uppercase text-muted-foreground">
                    {t('settlement.selectionTotal')}
                  </span>
                  <span className="flex flex-wrap items-baseline gap-2">
                    <MoneyText
                      laari={selectedTotalLaari}
                      className="text-lg font-semibold text-mono"
                    />
                    <span className="text-xs text-muted-foreground">
                      {t('settlement.selectedCount', {
                        count: selectedRows.length,
                      })}
                    </span>
                  </span>
                  <span className="text-xs text-muted-foreground">
                    {t('settlement.selectionTotalHint')}
                  </span>
                </div>
                <Button disabled={!canContinue} onClick={() => setStep(1)}>
                  <HandCoins />
                  {t('common.continue')}
                </Button>
              </CardFooter>
            </>
          )}
        </Card>
      )}

      {step === 1 && (
        <ReviewStep
          preview={preview}
          walletBalanceLaari={wallet.data?.balance_laari}
          walletPending={walletSettle.isPending}
          onBack={() => setStep(0)}
          submitPending={submitReceipt.isPending}
          submitError={submitReceipt.error}
          destinationId={destinationId}
          onSelectDestination={setDestinationId}
          onSubmitReceipt={(receipt) => {
            if (selection === null) return;
            submitReceipt.mutate(
              {
                selection,
                receipt: {
                  ...receipt,
                  platformBankAccountId: destinationId ?? undefined,
                },
              },
              { onSuccess: (settlement) => setCreated(settlement) },
            );
          }}
          // The discount's way back, offered only while a SUBSET is selected:
          // switching re-prices against settle_all, and the server decides.
          onSettleEverything={mode === 'all' ? undefined : selectEverything}
          onWalletSettle={() => {
            if (selection === null) return;
            walletSettle.mutate(selection, {
              onSuccess: (settlement) => {
                toast.success(t('settlement.walletSettleDone'));
                setCreated(settlement);
              },
              onError: (error) =>
                toast.error(
                  apiErrorMessage(error, t('settlement.walletSettleFailed')),
                ),
            });
          }}
        />
      )}

    </div>
  );
}

function StepIndicator({ current }: { current: number }) {
  const { t } = useTranslation();
  const labels = [t('settlement.stepSelect'), t('settlement.stepTransfer')];

  return (
    <nav aria-label={t('settlement.title')}>
      <p className="mb-2 text-xs text-muted-foreground sm:hidden">
        {t('settlement.progress', {
          current: current + 1,
          total: STEPS.length,
        })}
      </p>
      <ol className="flex items-center gap-1.5">
        {STEPS.map((id, index) => {
          const done = index < current;
          const active = index === current;
          return (
            <li key={id} className="flex min-w-0 items-center gap-1.5">
              <span
                aria-current={active ? 'step' : undefined}
                className="flex items-center gap-2"
              >
                <span
                  className={cn(
                    'inline-flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-medium',
                    active
                      ? 'bg-primary text-primary-foreground'
                      : done
                        ? 'bg-primary/15 text-primary'
                        : 'bg-muted text-muted-foreground',
                  )}
                >
                  {done ? <Check className="size-3.5" /> : index + 1}
                </span>
                <span
                  className={cn(
                    'hidden truncate text-xs sm:inline',
                    active
                      ? 'font-medium text-foreground'
                      : 'text-muted-foreground',
                  )}
                >
                  {labels[index]}
                </span>
              </span>
              {index < STEPS.length - 1 && (
                <span className="h-px w-4 shrink-0 bg-border" aria-hidden />
              )}
            </li>
          );
        })}
      </ol>
    </nav>
  );
}

function ReviewStep({
  preview,
  walletBalanceLaari,
  walletPending,
  onBack,
  submitPending,
  submitError,
  onSubmitReceipt,
  onSettleEverything,
  onWalletSettle,
  destinationId,
  onSelectDestination,
}: {
  preview: ReturnType<typeof useSettlementPreview>;
  walletBalanceLaari: number | undefined;
  walletPending: boolean;
  onBack: () => void;
  submitPending: boolean;
  submitError: unknown;
  onSubmitReceipt: (receipt: ReceiptSubmission) => void;
  /** Present only while a SUBSET is selected — the discount's way back. */
  onSettleEverything?: () => void;
  onWalletSettle: () => void;
  /** The platform account the merchant says they paid; null until chosen. */
  destinationId: number | null;
  onSelectDestination: (id: number) => void;
}) {
  const { t } = useTranslation();

  if (preview.error) {
    // A domain refusal names internal states in its message; say it the way
    // the merchant would. Anything else keeps the server's own wording.
    return (
      <Card className="mb-7.5">
        {isSelectionRefusal(preview.error) ? (
          <div className="p-5">
            <Alert variant="warning" appearance="light">
              <AlertIcon>
                <TriangleAlert />
              </AlertIcon>
              <AlertTitle>{t('settlement.notEligible')}</AlertTitle>
            </Alert>
          </div>
        ) : (
          <ErrorBlock
            error={preview.error}
            fallback={t('settlement.previewFailed')}
          />
        )}
        <CardFooter>
          <Button variant="outline" onClick={onBack}>
            {t('common.back')}
          </Button>
        </CardFooter>
      </Card>
    );
  }

  if (!preview.data) {
    return (
      <Card className="mb-7.5">
        <LoadingBlock lines={6} />
      </Card>
    );
  }

  const data = preview.data;
  const nothingDue = data.amount_due_laari === 0;
  const walletSufficient =
    walletBalanceLaari !== undefined &&
    walletBalanceLaari >= data.amount_due_laari;
  // Granted by the server for exactly this selection, capped at what is due.
  const discounted = data.discount_laari > 0;

  return (
    <div className="mb-7.5 grid grid-cols-1 items-start gap-5 lg:grid-cols-3">
      <Card className="lg:col-span-2">
        <CardHeader>
          <CardTitle>
            {nothingDue
              ? t('settlement.nothingDueTitle')
              : t('settlement.reviewTitle')}
          </CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-5">
          <PromptDiscountNotice
            discount={data.discount}
            variant="priced"
            onSettleEverything={onSettleEverything}
          />
          {nothingDue ? (
            <p className="text-sm text-secondary-foreground">
              {t('settlement.nothingDueBody')}
            </p>
          ) : (
            <>
              <PaymentInstructions
                reference={null}
                amountDueLaari={data.amount_due_laari}
                bankAccount={data.payment_instructions.bank_account}
                bankAccounts={data.payment_instructions.bank_accounts}
                selectedAccountId={
                  // Preselected ONLY when there is nothing to choose. With
                  // two banks on offer a default is a decision made for
                  // someone about where their money goes.
                  destinationId ??
                  (data.payment_instructions.bank_accounts.length === 1
                    ? data.payment_instructions.bank_accounts[0].id
                    : null)
                }
                onSelectAccount={onSelectDestination}
                needsConfiguration={
                  data.payment_instructions.needs_configuration
                }
              />

              {/* The receipt lives on this same screen deliberately: the
                  merchant transfers in their banking app and comes straight
                  back to the slip field, with the amount and reference still
                  in front of them. Submitting is what creates the
                  settlement — there is no receiptless path. */}
              <div className="flex flex-col gap-4 border-t border-border pt-5">
                <div className="flex flex-col gap-1">
                  <h3 className="text-sm font-medium text-mono">
                    {t('settlement.uploadTitle')}
                  </h3>
                  <p className="text-sm text-secondary-foreground">
                    {t('settlement.uploadLead')}
                  </p>
                </div>
                <ReceiptForm
                  amountDueLaari={data.amount_due_laari}
                  submitLabel={t('settlement.submitReceipt')}
                  pending={submitPending}
                  error={submitError}
                  footerStart={
                    <Button
                      variant="outline"
                      onClick={onBack}
                      disabled={submitPending}
                    >
                      {t('common.back')}
                    </Button>
                  }
                  onSubmit={onSubmitReceipt}
                />
              </div>
            </>
          )}
        </CardContent>
        {nothingDue && (
          <CardFooter className="flex flex-wrap items-center justify-between gap-3">
            <Button variant="outline" onClick={onBack}>
              {t('common.back')}
            </Button>
            <Button onClick={onWalletSettle} disabled={walletPending}>
              {walletPending ? (
                <LoaderCircle className="animate-spin" />
              ) : (
                <CircleCheck />
              )}
              {t('settlement.confirmNothingDue')}
            </Button>
          </CardFooter>
        )}
      </Card>

      <div className="flex flex-col gap-5">
        <Card>
          <CardHeader>
            <CardTitle>{t('settlement.summaryTitle')}</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-1.5 text-sm">
            <div className="flex justify-between gap-3">
              <span className="text-muted-foreground">
                {t('settlement.transactionsIncluded', {
                  count: data.transaction_count,
                })}
              </span>
              <MoneyText laari={data.line_total_laari} />
            </div>
            <div className="flex justify-between gap-3">
              <span className="text-muted-foreground">
                {t('settlement.summaryCashback')}
              </span>
              {/* Never discounted: the customer's reward is untouched (§1). */}
              <MoneyText laari={data.cashback_total_laari} />
            </div>
            <div className="flex justify-between gap-3">
              <span className="text-muted-foreground">
                {t('settlement.summaryFee')}
              </span>
              <DiscountedFeeAmount
                feeLaari={data.fee_total_laari}
                feeDiscountLaari={data.discount.fee_discount_laari}
              />
            </div>
            <div className="flex justify-between gap-3">
              <span className="text-muted-foreground">
                {t('settlement.summaryGst')}
              </span>
              <DiscountedFeeAmount
                feeLaari={data.fee_gst_total_laari}
                feeDiscountLaari={data.discount.gst_relief_laari}
              />
            </div>
            {discounted && (
              <div className="flex flex-col gap-0.5 border-t border-border pt-1.5">
                <PromptDiscountRow
                  ratePercent={data.discount.rate_percent}
                  discountLaari={data.discount_laari}
                />
                <span className="text-xs text-muted-foreground">
                  {t('settlement.discountAdvisory', {
                    days: data.discount.max_age_days,
                  })}
                </span>
              </div>
            )}
            {data.credit_applied_laari > 0 && (
              <div className="flex flex-col gap-0.5 border-t border-border pt-1.5">
                <div className="flex justify-between gap-3">
                  <span className="text-muted-foreground">
                    {t('settlement.creditApplied')}
                  </span>
                  <MoneyText laari={-data.credit_applied_laari} />
                </div>
                <span className="text-xs text-muted-foreground">
                  {t('settlement.creditAppliedHint')}
                </span>
              </div>
            )}
            <div className="flex justify-between gap-3 border-t border-border pt-1.5 font-medium">
              <span>{t('settlement.amountToTransfer')}</span>
              <MoneyText laari={data.amount_due_laari} />
            </div>
            {data.due_at !== null && (
              <span className="text-xs text-muted-foreground">
                {t('settlement.oldestDue', {
                  date: format(new Date(data.due_at), 'dd MMM yyyy'),
                })}
              </span>
            )}
          </CardContent>
        </Card>

        {!nothingDue && (
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <WalletIcon className="size-4 text-muted-foreground" />
                {t('settlement.walletTitle')}
              </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-3">
              <span className="text-sm text-secondary-foreground">
                {walletBalanceLaari === undefined
                  ? '…'
                  : t('settlement.walletBalance', {
                      amount: formatLaari(walletBalanceLaari),
                    })}
              </span>
              {walletBalanceLaari !== undefined && !walletSufficient && (
                <span className="text-xs text-muted-foreground">
                  {t('settlement.walletShort')}
                </span>
              )}
              <Button
                variant="outline"
                disabled={!walletSufficient || walletPending}
                onClick={onWalletSettle}
              >
                {walletPending ? (
                  <LoaderCircle className="animate-spin" />
                ) : (
                  <WalletIcon />
                )}
                {t('settlement.walletSettle')}
              </Button>
            </CardContent>
          </Card>
        )}
      </div>
    </div>
  );
}
