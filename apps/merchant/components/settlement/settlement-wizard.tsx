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
import type { MerchantSettlement, SettlementSelection } from '@/lib/api';
import {
  apiErrorMessage,
  isSelectionRefusal,
  useOutstanding,
  useSettlementPreview,
  useSubmitSettlementReceipt,
  useTransactions,
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
  CardTable,
  CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
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
import { ListPagination } from '@/components/app/list-pagination';
import { PaymentInstructions } from '@/components/settlement/payment-instructions';
import { ReceiptForm } from '@/components/settlement/receipt-form';

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
 */

const STEPS = ['select', 'review', 'upload'] as const;

export function SettlementWizard() {
  const { t } = useTranslation();
  const [step, setStep] = useState(0);
  const [mode, setMode] = useState<'all' | 'pick'>('all');
  const [selectedIds, setSelectedIds] = useState<number[]>([]);
  const [page, setPage] = useState(1);
  const [created, setCreated] = useState<MerchantSettlement | null>(null);

  const outstanding = useOutstanding();
  const payable = useTransactions('payable_unfunded', page);
  const wallet = useWallet();
  const submitReceipt = useSubmitSettlementReceipt();
  const walletSettle = useWalletSettleSelection();

  const selection: SettlementSelection | null = useMemo(() => {
    if (mode === 'all') return { settleAll: true };
    return selectedIds.length > 0 ? { transactionIds: selectedIds } : null;
  }, [mode, selectedIds]);

  // Priced from step 2 onwards and kept for step 3, so the amount the
  // merchant was told to transfer is the amount the receipt form prefills.
  const preview = useSettlementPreview(
    selection,
    step >= 1 && created === null,
  );

  const toggle = (id: number, checked: boolean) => {
    setMode('pick');
    setSelectedIds((current) =>
      checked
        ? current.includes(id)
          ? current
          : [...current, id]
        : current.filter((value) => value !== id),
    );
  };

  const pageIds = payable.data?.data.map((transaction) => transaction.id) ?? [];
  const allOnPageSelected =
    pageIds.length > 0 && pageIds.every((id) => selectedIds.includes(id));

  const togglePage = (checked: boolean) => {
    setMode('pick');
    setSelectedIds((current) =>
      checked
        ? Array.from(new Set([...current, ...pageIds]))
        : current.filter((id) => !pageIds.includes(id)),
    );
  };

  const nothingOutstanding = outstanding.data?.total.count === 0;
  const canContinue = selection !== null && !nothingOutstanding;

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
          <CardContent className="flex flex-col gap-5">
            <RadioGroup
              value={mode}
              onValueChange={(value) => setMode(value as 'all' | 'pick')}
              className="flex flex-col gap-3"
            >
              <div className="flex items-start gap-2.5">
                <RadioGroupItem
                  value="all"
                  id="settle-all"
                  className="mt-0.5"
                />
                <div className="flex flex-col gap-0.5">
                  <Label htmlFor="settle-all">
                    {t('settlement.settleAll')}
                  </Label>
                  <span className="text-xs text-muted-foreground">
                    {t('settlement.settleAllHint')}
                  </span>
                </div>
              </div>
              <div className="flex items-start gap-2.5">
                <RadioGroupItem
                  value="pick"
                  id="settle-pick"
                  className="mt-0.5"
                />
                <div className="flex flex-col gap-0.5">
                  <Label htmlFor="settle-pick">
                    {t('settlement.settleSome')}
                  </Label>
                  <span className="text-xs text-muted-foreground">
                    {mode === 'pick' && selectedIds.length > 0
                      ? t('settlement.selectedCount', {
                          count: selectedIds.length,
                        })
                      : t('settlement.settleSomeHint')}
                  </span>
                </div>
              </div>
            </RadioGroup>

            {mode === 'pick' && selectedIds.length === 0 && (
              <p className="text-xs text-muted-foreground">
                {t('settlement.selectAtLeastOne')}
              </p>
            )}
          </CardContent>

          {payable.error ? (
            <ErrorBlock error={payable.error} />
          ) : !payable.data ? (
            <LoadingBlock lines={5} />
          ) : payable.data.data.length === 0 ? (
            <EmptyBlock>{t('settlement.emptyPayable')}</EmptyBlock>
          ) : (
            <>
              <CardTable>
                <div className="overflow-x-auto">
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead className="w-10">
                          {mode === 'pick' && (
                            <Checkbox
                              checked={allOnPageSelected}
                              onCheckedChange={(checked) =>
                                togglePage(checked === true)
                              }
                              aria-label={t('settlement.selectAllOnPage')}
                            />
                          )}
                        </TableHead>
                        <TableHead>{t('settlement.colInvoice')}</TableHead>
                        <TableHead>{t('settlement.colDate')}</TableHead>
                        <TableHead className="text-end">
                          {t('settlement.colCashback')}
                        </TableHead>
                        <TableHead className="text-end">
                          {t('settlement.colFees')}
                        </TableHead>
                        <TableHead className="text-end">
                          {t('settlement.colDue')}
                        </TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {payable.data.data.map((transaction) => (
                        <TableRow key={transaction.id}>
                          <TableCell>
                            {mode === 'pick' ? (
                              <Checkbox
                                checked={selectedIds.includes(transaction.id)}
                                onCheckedChange={(checked) =>
                                  toggle(transaction.id, checked === true)
                                }
                                aria-label={t('settlement.selectRow', {
                                  invoice: transaction.invoice_no,
                                })}
                              />
                            ) : (
                              <Check
                                className="size-4 text-primary"
                                aria-hidden
                              />
                            )}
                          </TableCell>
                          <TableCell className="font-medium text-mono">
                            {transaction.invoice_no}
                          </TableCell>
                          <TableCell className="whitespace-nowrap text-secondary-foreground">
                            {format(
                              new Date(transaction.occurred_at),
                              'dd MMM yyyy',
                            )}
                          </TableCell>
                          <TableCell className="text-end">
                            <MoneyText laari={transaction.cashback_laari} />
                          </TableCell>
                          <TableCell className="text-end">
                            <MoneyText
                              laari={
                                transaction.fee_laari +
                                transaction.fee_gst_laari
                              }
                            />
                          </TableCell>
                          <TableCell className="text-end font-medium">
                            <MoneyText
                              laari={
                                transaction.cashback_laari +
                                transaction.fee_laari +
                                transaction.fee_gst_laari
                              }
                            />
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
              </CardTable>
              <CardFooter className="flex flex-wrap items-center justify-between gap-3">
                <ListPagination
                  meta={payable.data.meta}
                  onPageChange={setPage}
                />
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
          onContinue={() => setStep(2)}
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

      {step === 2 && (
        <Card className="mb-7.5">
          <CardHeader>
            <CardTitle>{t('settlement.uploadTitle')}</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-5">
            <p className="text-sm text-secondary-foreground">
              {t('settlement.uploadLead')}
            </p>
            {preview.data === undefined ? (
              <LoadingBlock lines={4} />
            ) : (
              <ReceiptForm
                amountDueLaari={preview.data.amount_due_laari}
                submitLabel={t('settlement.submitReceipt')}
                pending={submitReceipt.isPending}
                error={submitReceipt.error}
                footerStart={
                  <Button
                    variant="outline"
                    onClick={() => setStep(1)}
                    disabled={submitReceipt.isPending}
                  >
                    {t('common.back')}
                  </Button>
                }
                onSubmit={(receipt) => {
                  if (selection === null) return;
                  submitReceipt.mutate(
                    { selection, receipt },
                    { onSuccess: (settlement) => setCreated(settlement) },
                  );
                }}
              />
            )}
          </CardContent>
        </Card>
      )}
    </div>
  );
}

function StepIndicator({ current }: { current: number }) {
  const { t } = useTranslation();
  const labels = [
    t('settlement.stepSelect'),
    t('settlement.stepReview'),
    t('settlement.stepUpload'),
  ];

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
  onContinue,
  onWalletSettle,
}: {
  preview: ReturnType<typeof useSettlementPreview>;
  walletBalanceLaari: number | undefined;
  walletPending: boolean;
  onBack: () => void;
  onContinue: () => void;
  onWalletSettle: () => void;
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
        <CardContent>
          {nothingDue ? (
            <p className="text-sm text-secondary-foreground">
              {t('settlement.nothingDueBody')}
            </p>
          ) : (
            <PaymentInstructions
              reference={data.payment_instructions.reference_preview}
              referenceIsFinal={data.payment_instructions.reference_is_final}
              amountDueLaari={data.amount_due_laari}
              bankAccount={data.payment_instructions.bank_account}
              needsConfiguration={data.payment_instructions.needs_configuration}
            />
          )}
        </CardContent>
        <CardFooter className="flex flex-wrap items-center justify-between gap-3">
          <Button variant="outline" onClick={onBack}>
            {t('common.back')}
          </Button>
          {nothingDue ? (
            <Button onClick={onWalletSettle} disabled={walletPending}>
              {walletPending ? (
                <LoaderCircle className="animate-spin" />
              ) : (
                <CircleCheck />
              )}
              {t('settlement.confirmNothingDue')}
            </Button>
          ) : (
            <Button onClick={onContinue}>
              {t('settlement.haveTransferred')}
            </Button>
          )}
        </CardFooter>
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
              <MoneyText laari={data.cashback_total_laari} />
            </div>
            <div className="flex justify-between gap-3">
              <span className="text-muted-foreground">
                {t('settlement.summaryFee')}
              </span>
              <MoneyText laari={data.fee_total_laari} />
            </div>
            <div className="flex justify-between gap-3">
              <span className="text-muted-foreground">
                {t('settlement.summaryGst')}
              </span>
              <MoneyText laari={data.fee_gst_total_laari} />
            </div>
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
