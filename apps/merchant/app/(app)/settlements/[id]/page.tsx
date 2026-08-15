'use client';

import { use, useState, type ReactNode } from 'react';
import Link from 'next/link';
import { formatLaari } from '@manfaa/api-client';
import { MoneyText } from '@manfaa/ui';
import { format } from 'date-fns';
import {
  BadgeCheck,
  Ban,
  FileCheck2,
  FileX2,
  Hourglass,
  Landmark,
  Plus,
  ShieldQuestion,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';
import {
  fundingMethodLabel,
  settlementPaymentStateLabel,
} from '@/lib/labels';
import { toast } from 'sonner';
import type { MerchantSettlement, ReceiptPayment } from '@/lib/api';
import { useAddSettlementReceipt, useSettlement } from '@/lib/queries';
import { hasRoleAtLeast } from '@/lib/roles';
import {
  Alert,
  AlertContent,
  AlertDescription,
  AlertIcon,
  AlertTitle,
} from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Card,
  CardContent,
  CardHeader,
  CardTable,
  CardTitle,
} from '@/components/ui/card';
import {
  Table,
  TableBody,
  TableCell,
  TableFooter,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { useLayout } from '@/components/app-layout/context';
import {
  Toolbar,
  ToolbarActions,
  ToolbarDescription,
  ToolbarHeading,
  ToolbarPageTitle,
} from '@/components/app-layout/toolbar';
import { ErrorBlock, LoadingBlock } from '@/components/app/async-states';
import { SettlementStateBadge } from '@/components/app/state-badge';
import { PaymentInstructions } from '@/components/settlement/payment-instructions';
import { PromptDiscountRow } from '@/components/settlement/prompt-discount';
import { ReceiptForm } from '@/components/settlement/receipt-form';

/**
 * One settlement, as the merchant sees it under the receipt-first flow
 * (PLAN §1). The screen answers "what is happening to my transfer" from the
 * API's `merchant_status`: verifying, settled, partially settled, or —
 * when an admin refused the slip — REJECTED, with the reason they gave and
 * a one-click route to a fresh settlement.
 *
 * There is no submit button here any more: a settlement cannot exist without
 * its receipt, so submitting happens in the wizard. What remains is paying
 * off a batch that is still owed money — the remainder after a §7 partial
 * payment (whose uncovered lines stay frozen here), or an admin-built
 * fallback batch sitting in awaiting_payment.
 */

/** What the merchant still owes on this batch, never negative. */
function remainingLaari(settlement: MerchantSettlement): number {
  return Math.max(
    settlement.amount_due_laari - settlement.amount_received_laari,
    0,
  );
}

function StatusCard({ settlement }: { settlement: MerchantSettlement }) {
  const { t } = useTranslation();
  const status = settlement.merchant_status;

  const copy: Record<
    typeof status.code,
    {
      title: string;
      body?: string;
      variant: 'info' | 'success' | 'warning' | 'destructive' | 'secondary';
      icon: ReactNode;
    }
  > = {
    verifying: {
      title: t('settlement.statusVerifying'),
      body: t('settlement.statusVerifyingBody'),
      variant: 'info',
      icon: <Hourglass />,
    },
    settled: {
      title: t('settlement.statusSettled'),
      variant: 'success',
      icon: <BadgeCheck />,
    },
    partially_settled: {
      title: t('settlement.statusPartiallySettled'),
      variant: 'warning',
      icon: <ShieldQuestion />,
    },
    awaiting_payment: {
      title: t('settlement.statusAwaitingPayment'),
      body: t('settlement.statusAwaitingPaymentBody'),
      variant: 'warning',
      icon: <Landmark />,
    },
    rejected: {
      title: t('settlement.statusRejected'),
      body: t('settlement.statusRejectedBody'),
      variant: 'destructive',
      icon: <FileX2 />,
    },
    cancelled: {
      title: t('settlement.statusCancelled'),
      variant: 'secondary',
      icon: <Ban />,
    },
    draft: {
      title: t('settlement.statusDraft'),
      variant: 'secondary',
      icon: <Hourglass />,
    },
  };

  const shown = copy[status.code];
  const rejection = status.rejection;

  return (
    <Alert variant={shown.variant} appearance="light">
      <AlertIcon>{shown.icon}</AlertIcon>
      <AlertContent>
        <AlertTitle>{shown.title}</AlertTitle>
        {shown.body !== undefined && (
          <AlertDescription>{shown.body}</AlertDescription>
        )}
        {rejection !== null && (
          <AlertDescription>
            <span className="mt-2 flex flex-col gap-1">
              <span className="text-xs font-medium uppercase text-muted-foreground">
                {t('settlement.statusRejectedReason')}
              </span>
              <span className="text-mono">
                {rejection.reason ?? t('settlement.statusRejectedNoReason')}
              </span>
              {rejection.rejected_at !== null && (
                <span className="text-xs text-muted-foreground">
                  {format(
                    new Date(rejection.rejected_at),
                    'dd MMM yyyy, HH:mm',
                  )}
                </span>
              )}
            </span>
          </AlertDescription>
        )}
      </AlertContent>
    </Alert>
  );
}

function PaymentRow({ payment }: { payment: ReceiptPayment }) {
  const { t } = useTranslation();
  const stateLabel = settlementPaymentStateLabel(t, payment.state);

  return (
    <div className="flex flex-col gap-1 border-b border-border pb-3 last:border-0 last:pb-0">
      <div className="flex items-center justify-between gap-3 text-sm">
        <span className="font-medium text-mono" dir="ltr">
          {payment.bank_ref ?? fundingMethodLabel(t, payment.method)}
        </span>
        <MoneyText laari={payment.amount_laari} className="font-medium" />
      </div>
      <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
        <span>
          {format(new Date(payment.created_at), 'dd MMM yyyy, HH:mm')}
        </span>
        <Badge
          size="sm"
          appearance="light"
          variant={
            payment.state === 'matched'
              ? 'success'
              : payment.state === 'rejected'
                ? 'destructive'
                : 'info'
          }
        >
          {stateLabel}
        </Badge>
        <span className="flex items-center gap-1">
          {payment.has_slip ? (
            <>
              <FileCheck2 className="size-3.5" />
              {t('settlement.paymentSlip')}
            </>
          ) : (
            <>
              <FileX2 className="size-3.5" />
              {t('settlement.paymentNoSlip')}
            </>
          )}
        </span>
      </div>
      {payment.rejection_reason !== null && (
        <span className="text-xs text-destructive">
          {payment.rejection_reason}
        </span>
      )}
    </div>
  );
}

export default function SettlementDetailPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id: idParam } = use(params);
  const id = Number(idParam);
  const { t } = useTranslation();
  const { me } = useLayout();
  // Paying a batch is a settlement MUTATION — manager or owner (PLAN §1).
  // Staff keep the read-only view; the API refuses the POST either way.
  const canManage = hasRoleAtLeast(me.role, 'manager');
  const settlementQuery = useSettlement(id);
  const addReceipt = useAddSettlementReceipt(id);
  const [receiptOpen, setReceiptOpen] = useState(false);

  if (settlementQuery.error) {
    return (
      <div className="container">
        <ErrorBlock
          error={settlementQuery.error}
          fallback={t('settlement.notFound')}
        />
      </div>
    );
  }

  const settlement = settlementQuery.data;
  if (!settlement) {
    return (
      <div className="container">
        <LoadingBlock lines={6} />
      </div>
    );
  }

  const status = settlement.merchant_status;
  const owes = remainingLaari(settlement);
  // A further receipt is accepted on an unsettled, submitted batch
  // (awaiting_payment or partially_settled). While one is already under
  // review, the honest answer is "we are checking it", not "pay again".
  const canPay =
    canManage &&
    owes > 0 &&
    (settlement.state === 'awaiting_payment' ||
      settlement.state === 'partially_settled');

  return (
    <div className="container">
      <Toolbar>
        <ToolbarHeading>
          <ToolbarPageTitle>{settlement.reference}</ToolbarPageTitle>
          <ToolbarDescription>
            <SettlementStateBadge state={settlement.state} />
            <span>
              {t('settlement.detailCreated', {
                date: format(
                  new Date(settlement.created_at),
                  'dd MMM yyyy, HH:mm',
                ),
              })}
            </span>
          </ToolbarDescription>
        </ToolbarHeading>
        {status.code === 'rejected' && canManage && (
          <ToolbarActions>
            <Button asChild>
              <Link href="/settlements/new">
                <Plus />
                {t('settlement.createNew')}
              </Link>
            </Button>
          </ToolbarActions>
        )}
      </Toolbar>

      <div className="grid grid-cols-1 items-start gap-5 pb-7.5 lg:grid-cols-3">
        <div className="flex flex-col gap-5 lg:col-span-2">
          <StatusCard settlement={settlement} />

          {canPay && (
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Landmark className="size-4 text-muted-foreground" />
                  {settlement.state === 'awaiting_payment'
                    ? t('settlement.statusAwaitingPayment')
                    : t('settlement.remainderTitle')}
                </CardTitle>
              </CardHeader>
              <CardContent className="flex flex-col gap-5">
                {settlement.state === 'partially_settled' && (
                  <p className="text-sm text-secondary-foreground">
                    {t('settlement.remainderBody', {
                      amount: formatLaari(owes),
                    })}
                  </p>
                )}
                <PaymentInstructions
                  reference={settlement.payment_instructions.reference}
                  referenceIsFinal
                  amountDueLaari={owes}
                  bankAccount={settlement.payment_instructions.bank_account}
                  needsConfiguration={
                    settlement.payment_instructions.needs_configuration
                  }
                />
                {receiptOpen ? (
                  <ReceiptForm
                    amountDueLaari={owes}
                    submitLabel={t('settlement.submitReceipt')}
                    pending={addReceipt.isPending}
                    error={addReceipt.error}
                    onSubmit={(receipt) =>
                      addReceipt.mutate(receipt, {
                        onSuccess: () => {
                          setReceiptOpen(false);
                          toast.success(t('settlement.receiptAdded'));
                        },
                      })
                    }
                  />
                ) : (
                  <div>
                    <Button onClick={() => setReceiptOpen(true)}>
                      {t('settlement.uploadReceiptAction')}
                    </Button>
                  </div>
                )}
              </CardContent>
            </Card>
          )}

          <Card>
            <CardHeader>
              <CardTitle>
                {t('settlement.linesTitle', {
                  count: settlement.lines?.length ?? 0,
                })}
              </CardTitle>
            </CardHeader>
            <CardTable>
              <div className="overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>{t('settlement.colInvoice')}</TableHead>
                      <TableHead>{t('settlement.colDate')}</TableHead>
                      <TableHead className="text-end">
                        {t('settlement.colCashback')}
                      </TableHead>
                      <TableHead className="text-end">
                        {t('settlement.colFee')}
                      </TableHead>
                      <TableHead className="text-end">
                        {t('settlement.colGst')}
                      </TableHead>
                      <TableHead className="text-end">
                        {t('settlement.colDue')}
                      </TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {(settlement.lines ?? []).map((line) => (
                      <TableRow key={line.id}>
                        <TableCell className="font-medium text-mono">
                          {line.transaction?.invoice_no ??
                            `#${line.transaction_id}`}
                        </TableCell>
                        <TableCell className="whitespace-nowrap text-secondary-foreground">
                          {line.transaction
                            ? format(
                                new Date(line.transaction.occurred_at),
                                'dd MMM yyyy',
                              )
                            : '—'}
                        </TableCell>
                        <TableCell className="text-end">
                          <MoneyText laari={line.cashback_laari} />
                        </TableCell>
                        <TableCell className="text-end">
                          <MoneyText laari={line.fee_laari} />
                        </TableCell>
                        <TableCell className="text-end">
                          <MoneyText laari={line.fee_gst_laari} />
                        </TableCell>
                        <TableCell className="text-end font-medium">
                          <MoneyText laari={line.due_laari} />
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                  <TableFooter>
                    <TableRow>
                      <TableCell colSpan={2} className="font-medium">
                        {t('settlement.totals')}
                      </TableCell>
                      <TableCell className="text-end font-medium">
                        <MoneyText laari={settlement.cashback_total_laari} />
                      </TableCell>
                      <TableCell className="text-end font-medium">
                        <MoneyText laari={settlement.fee_total_laari} />
                      </TableCell>
                      <TableCell className="text-end font-medium">
                        <MoneyText laari={settlement.fee_gst_total_laari} />
                      </TableCell>
                      <TableCell className="text-end font-semibold">
                        <MoneyText laari={settlement.amount_due_laari} />
                      </TableCell>
                    </TableRow>
                  </TableFooter>
                </Table>
              </div>
            </CardTable>
          </Card>
        </div>

        <div className="flex flex-col gap-5">
          <Card>
            <CardHeader>
              <CardTitle>{t('settlement.summaryTitle')}</CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-1.5 text-sm">
              <div className="flex justify-between gap-3">
                <span className="text-muted-foreground">
                  {t('settlement.summaryCashback')}
                </span>
                <MoneyText laari={settlement.cashback_total_laari} />
              </div>
              <div className="flex justify-between gap-3">
                <span className="text-muted-foreground">
                  {t('settlement.summaryFee')}
                </span>
                <MoneyText laari={settlement.fee_total_laari} />
              </div>
              <div className="flex justify-between gap-3">
                <span className="text-muted-foreground">
                  {t('settlement.summaryGst')}
                </span>
                <MoneyText laari={settlement.fee_gst_total_laari} />
              </div>
              {/*
                PLAN §1: the prompt-payment discount this batch was GRANTED at
                submit — 5% off the platform fee, never off the customer's
                cashback. It is already subtracted from `amount_due_laari`, so
                the line is shown as the relief it was, not applied again. The
                rate stamped on the settlement is the rate it was priced at,
                which is not necessarily the rate the platform charges today.
              */}
              {settlement.discount_laari > 0 && (
                <div className="flex flex-col gap-0.5">
                  <PromptDiscountRow
                    rateBp={settlement.discount_rate_bp}
                    discountLaari={settlement.discount_laari}
                  />
                  <span className="text-xs text-muted-foreground">
                    {t('settlement.discountAppliedHint')}
                  </span>
                </div>
              )}
              <div className="flex justify-between gap-3 border-t border-border pt-1.5 font-medium">
                <span>{t('settlement.summaryDue')}</span>
                <MoneyText laari={settlement.amount_due_laari} />
              </div>
              <div className="flex justify-between gap-3">
                <span className="text-muted-foreground">
                  {t('settlement.summaryReceived')}
                </span>
                <MoneyText laari={settlement.amount_received_laari} />
              </div>
              <div className="flex justify-between gap-3">
                <span className="text-muted-foreground">
                  {t('settlement.summaryMethod')}
                </span>
                <span>
                  {settlement.funding_method === 'wallet'
                    ? t('settlement.methodWallet')
                    : t('settlement.methodBank')}
                </span>
              </div>
            </CardContent>
          </Card>

          {(settlement.payments?.length ?? 0) > 0 && (
            <Card>
              <CardHeader>
                <CardTitle>{t('settlement.paymentsTitle')}</CardTitle>
              </CardHeader>
              <CardContent className="flex flex-col gap-3">
                {(settlement.payments ?? []).map((payment) => (
                  <PaymentRow key={payment.id} payment={payment} />
                ))}
              </CardContent>
            </Card>
          )}
        </div>
      </div>
    </div>
  );
}
