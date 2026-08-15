'use client';

import { use } from 'react';
import { type Settlement, type SettlementState } from '@manfaa/api-client';
import { MoneyText } from '@manfaa/ui';
import { format } from 'date-fns';
import {
  Check,
  Copy,
  Landmark,
  LoaderCircle,
  Send,
  Wallet as WalletIcon,
} from 'lucide-react';
import { toast } from 'sonner';
import {
  apiErrorMessage,
  useSettlement,
  useSubmitSettlement,
  useWallet,
  useWalletSettle,
} from '@/lib/queries';
import { hasRoleAtLeast } from '@/lib/roles';
import { cn } from '@/lib/utils';
import { useCopyToClipboard } from '@/hooks/use-copy-to-clipboard';
import { useLayout } from '@/components/app-layout/context';
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
import {
  Toolbar,
  ToolbarActions,
  ToolbarDescription,
  ToolbarHeading,
  ToolbarPageTitle,
} from '@/components/app-layout/toolbar';
import { ErrorBlock, LoadingBlock } from '@/components/app/async-states';
import {
  SettlementStateBadge,
  settlementStateLabel,
} from '@/components/app/state-badge';

/** The happy-path lifecycle (§6); cancelled renders separately. */
const TIMELINE_STATES: SettlementState[] = [
  'draft',
  'awaiting_payment',
  'payment_review',
  'settled',
];

function StatusTimeline({ settlement }: { settlement: Settlement }) {
  const stateIndex =
    settlement.state === 'partially_settled'
      ? TIMELINE_STATES.indexOf('payment_review')
      : TIMELINE_STATES.indexOf(settlement.state);

  return (
    <div className="flex flex-col gap-0">
      {TIMELINE_STATES.map((state, index) => {
        const reached =
          settlement.state === 'cancelled' ? index === 0 : index <= stateIndex;
        const label =
          state === settlement.state ||
          (state === 'payment_review' &&
            settlement.state === 'partially_settled')
            ? settlementStateLabel(settlement.state)
            : settlementStateLabel(state);
        return (
          <div key={state} className="flex gap-3">
            <div className="flex flex-col items-center">
              <div
                className={cn(
                  'size-2.5 rounded-full mt-1.5 shrink-0',
                  reached ? 'bg-primary' : 'bg-border',
                )}
              />
              {index < TIMELINE_STATES.length - 1 && (
                <div
                  className={cn(
                    'w-px grow min-h-5',
                    index < stateIndex ? 'bg-primary' : 'bg-border',
                  )}
                />
              )}
            </div>
            <div className="pb-4">
              <div
                className={cn(
                  'text-sm font-medium',
                  reached ? 'text-mono' : 'text-muted-foreground',
                )}
              >
                {label}
              </div>
              {state === 'draft' && (
                <div className="text-xs text-muted-foreground">
                  Created{' '}
                  {format(
                    new Date(settlement.created_at),
                    'dd MMM yyyy, HH:mm',
                  )}
                </div>
              )}
              {state === 'awaiting_payment' && settlement.due_at && (
                <div className="text-xs text-muted-foreground">
                  Due {format(new Date(settlement.due_at), 'dd MMM yyyy')}
                </div>
              )}
            </div>
          </div>
        );
      })}
      {settlement.state === 'cancelled' && (
        <div className="flex gap-3">
          <div className="size-2.5 rounded-full mt-1.5 shrink-0 bg-destructive" />
          <div className="text-sm font-medium text-destructive">Cancelled</div>
        </div>
      )}
    </div>
  );
}

/** Icon button that copies `value` and flashes a check for a moment. */
function CopyButton({ value, label }: { value: string; label: string }) {
  const { isCopied, copyToClipboard } = useCopyToClipboard();

  return (
    <Button
      variant="outline"
      size="sm"
      mode="icon"
      aria-label={label}
      onClick={() => copyToClipboard(value)}
    >
      {isCopied ? <Check className="text-green-500" /> : <Copy />}
    </Button>
  );
}

function BankInstructions({ settlement }: { settlement: Settlement }) {
  const instructions = settlement.payment_instructions;
  const account = instructions.needs_configuration
    ? null
    : instructions.bank_account;

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <Landmark className="size-4 text-muted-foreground" />
          Bank transfer instructions
        </CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div className="flex flex-col gap-1">
            <span className="text-xs uppercase text-muted-foreground">
              Amount to transfer
            </span>
            <MoneyText
              laari={settlement.amount_due_laari}
              className="text-2xl font-semibold text-mono"
            />
          </div>
          <div className="flex flex-col gap-1">
            <span className="text-xs uppercase text-muted-foreground">
              Transfer reference — include it word for word
            </span>
            <div className="flex items-center gap-2">
              <code className="text-sm font-semibold bg-muted rounded-md px-2.5 py-1.5 text-mono">
                {instructions.reference}
              </code>
              <CopyButton
                value={instructions.reference}
                label="Copy reference"
              />
            </div>
          </div>
        </div>

        {account ? (
          <div className="rounded-md border border-border p-4">
            <div className="text-xs uppercase text-muted-foreground mb-3">
              Transfer to
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div className="flex flex-col gap-1">
                <span className="text-xs text-muted-foreground">Bank</span>
                <span className="text-sm font-medium text-mono">
                  {account.bank_name}
                </span>
              </div>
              <div className="flex flex-col gap-1">
                <span className="text-xs text-muted-foreground">
                  Account number
                </span>
                <div className="flex items-center gap-2">
                  <code
                    dir="ltr"
                    className="text-sm font-semibold bg-muted rounded-md px-2.5 py-1.5 text-mono"
                  >
                    {account.account_no}
                  </code>
                  <CopyButton
                    value={account.account_no}
                    label="Copy account number"
                  />
                </div>
              </div>
              <div className="flex flex-col gap-1">
                <span className="text-xs text-muted-foreground">
                  Account name
                </span>
                <span className="text-sm font-medium text-mono">
                  {account.account_name}
                </span>
              </div>
            </div>
          </div>
        ) : (
          <div className="rounded-md border border-border bg-muted/50 p-4 text-sm text-secondary-foreground">
            Transfer details are being finalised — contact Manfaa for the
            account to pay into. Your settlement and its reference are
            unaffected.
          </div>
        )}

        <p className="text-sm text-muted-foreground">
          Transfer the exact amount by domestic bank transfer. We match incoming
          payments by reference; your batch settles once the payment is matched.
        </p>
      </CardContent>
    </Card>
  );
}

export default function SettlementDetailPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id: idParam } = use(params);
  const id = Number(idParam);
  const { me } = useLayout();
  // Submit and wallet-settle are settlement MUTATIONS — manager or owner
  // (PLAN §1). Staff keep the read-only view of the batch.
  const canManage = hasRoleAtLeast(me.role, 'manager');
  const settlementQuery = useSettlement(id);
  const wallet = useWallet();
  const submitMutation = useSubmitSettlement(id);
  const walletSettleMutation = useWalletSettle(id);

  if (settlementQuery.error) {
    return (
      <div className="container">
        <ErrorBlock
          error={settlementQuery.error}
          fallback="Settlement not found."
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

  const walletBalance = wallet.data?.balance_laari;
  const walletSufficient =
    walletBalance !== undefined && walletBalance >= settlement.amount_due_laari;

  const handleSubmit = () => {
    submitMutation.mutate(undefined, {
      onSuccess: () =>
        toast.success('Settlement submitted — see the payment instructions.'),
      onError: (error) =>
        toast.error(apiErrorMessage(error, 'Could not submit the settlement.')),
    });
  };

  const handleWalletSettle = () => {
    walletSettleMutation.mutate(undefined, {
      onSuccess: () => toast.success('Settled from your wallet.'),
      onError: (error) =>
        toast.error(
          apiErrorMessage(error, 'Could not settle from the wallet.'),
        ),
    });
  };

  return (
    <div className="container">
      <Toolbar>
        <ToolbarHeading>
          <ToolbarPageTitle>{settlement.reference}</ToolbarPageTitle>
          <ToolbarDescription>
            <SettlementStateBadge state={settlement.state} />
            <span>
              Created{' '}
              {format(new Date(settlement.created_at), 'dd MMM yyyy, HH:mm')}
            </span>
          </ToolbarDescription>
        </ToolbarHeading>
        <ToolbarActions>
          {settlement.state === 'draft' && canManage && (
            <Button onClick={handleSubmit} disabled={submitMutation.isPending}>
              {submitMutation.isPending ? (
                <LoaderCircle className="animate-spin" />
              ) : (
                <Send />
              )}
              Submit for payment
            </Button>
          )}
        </ToolbarActions>
      </Toolbar>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-5 items-start pb-7.5">
        <div className="lg:col-span-2 flex flex-col gap-5">
          {settlement.state === 'awaiting_payment' && (
            <>
              <BankInstructions settlement={settlement} />
              <Card>
                <CardHeader>
                  <CardTitle className="flex items-center gap-2">
                    <WalletIcon className="size-4 text-muted-foreground" />
                    Or settle from your wallet
                  </CardTitle>
                </CardHeader>
                <CardContent className="flex flex-wrap items-center justify-between gap-3">
                  <div className="text-sm text-secondary-foreground">
                    Wallet balance:{' '}
                    {walletBalance !== undefined ? (
                      <MoneyText
                        laari={walletBalance}
                        className="font-medium"
                      />
                    ) : (
                      '…'
                    )}
                    {walletBalance !== undefined && !walletSufficient && (
                      <span className="text-muted-foreground">
                        {' '}
                        — not enough to cover this settlement
                      </span>
                    )}
                  </div>
                  <Button
                    variant="outline"
                    disabled={
                      !canManage ||
                      !walletSufficient ||
                      walletSettleMutation.isPending
                    }
                    onClick={handleWalletSettle}
                  >
                    {walletSettleMutation.isPending && (
                      <LoaderCircle className="animate-spin" />
                    )}
                    Settle from wallet
                  </Button>
                </CardContent>
              </Card>
            </>
          )}

          <Card>
            <CardHeader>
              <CardTitle>Lines ({settlement.lines?.length ?? 0})</CardTitle>
            </CardHeader>
            <CardTable>
              <div className="overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Invoice</TableHead>
                      <TableHead>Date</TableHead>
                      <TableHead className="text-end">Cashback</TableHead>
                      <TableHead className="text-end">Fee</TableHead>
                      <TableHead className="text-end">GST</TableHead>
                      <TableHead className="text-end">Due</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {(settlement.lines ?? []).map((line) => (
                      <TableRow key={line.id}>
                        <TableCell className="font-medium text-mono">
                          {line.transaction?.invoice_no ??
                            `#${line.transaction_id}`}
                        </TableCell>
                        <TableCell className="text-secondary-foreground whitespace-nowrap">
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
                        Totals
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
              <CardTitle>Summary</CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-1.5 text-sm">
              <div className="flex justify-between gap-3">
                <span className="text-muted-foreground">Customer cashback</span>
                <MoneyText laari={settlement.cashback_total_laari} />
              </div>
              <div className="flex justify-between gap-3">
                <span className="text-muted-foreground">Platform fee</span>
                <MoneyText laari={settlement.fee_total_laari} />
              </div>
              <div className="flex justify-between gap-3">
                <span className="text-muted-foreground">GST on fee</span>
                <MoneyText laari={settlement.fee_gst_total_laari} />
              </div>
              <div className="flex justify-between gap-3 border-t border-border pt-1.5 font-medium">
                <span>Amount due</span>
                <MoneyText laari={settlement.amount_due_laari} />
              </div>
              <div className="flex justify-between gap-3">
                <span className="text-muted-foreground">Received so far</span>
                <MoneyText laari={settlement.amount_received_laari} />
              </div>
              <div className="flex justify-between gap-3">
                <span className="text-muted-foreground">Funding method</span>
                <span className="capitalize">{settlement.funding_method}</span>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Status</CardTitle>
            </CardHeader>
            <CardContent>
              <StatusTimeline settlement={settlement} />
            </CardContent>
          </Card>

          {(settlement.payments?.length ?? 0) > 0 && (
            <Card>
              <CardHeader>
                <CardTitle>Payments</CardTitle>
              </CardHeader>
              <CardContent className="flex flex-col gap-3">
                {(settlement.payments ?? []).map((payment) => (
                  <div
                    key={payment.id}
                    className="flex items-center justify-between gap-3 text-sm"
                  >
                    <div className="flex flex-col">
                      <span className="font-medium text-mono capitalize">
                        {payment.method}
                        {payment.bank_ref ? ` · ${payment.bank_ref}` : ''}
                      </span>
                      <span className="text-xs text-muted-foreground">
                        {format(
                          new Date(payment.created_at),
                          'dd MMM yyyy, HH:mm',
                        )}{' '}
                        · {payment.state}
                      </span>
                    </div>
                    <MoneyText
                      laari={payment.amount_laari}
                      className="font-medium"
                    />
                  </div>
                ))}
              </CardContent>
            </Card>
          )}
        </div>
      </div>
    </div>
  );
}
