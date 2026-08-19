'use client';

import { useState } from 'react';
import {
  cancelPendingPayment,
  listPendingPayments,
  markPendingPaymentSent,
  sendPendingPayment,
  type PendingPayment,
} from '@manfaa/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { TriangleAlert } from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/lib/api-error';
import { formatMoney } from '@manfaa/ui';
import { Alert, AlertDescription, AlertIcon } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { PageHeader } from '@/components/admin/page-header';

/**
 * Money owed to customers, waiting to leave (PLAN-marketplace.md §21).
 *
 * Refunds land in a customer's wallet instantly; this queue is what happens
 * when they ask for it in their bank. Worked by hand until the tunnel exists
 * — and the queue does not change when it does, a worker simply drains the
 * same rows through the same sender.
 */
const TABS = [
  { key: 'pending', label: 'Pending' },
  { key: 'pending_approval', label: 'Awaiting approval' },
  { key: 'failed', label: 'Failed' },
  { key: 'sent', label: 'Sent' },
  { key: 'all', label: 'All' },
] as const;

export default function PendingPaymentsPage() {
  const [tab, setTab] = useState<string>('pending');

  const payments = useQuery({
    queryKey: ['admin', 'pending-payments', tab],
    queryFn: ({ signal }) => listPendingPayments(tab, { signal }),
  });

  const counts = payments.data?.meta.counts ?? {};

  return (
    <div className="flex flex-col gap-5">
      <PageHeader
        title="Pending payments"
        description="Withdrawals from customer wallets, waiting to be transferred. Refunds reach the wallet instantly; this is money leaving for a bank."
      />

      {payments.data && !payments.data.meta.auto_transfer_enabled ? (
        <Alert variant="info" appearance="light" size="sm">
          <AlertIcon>
            <TriangleAlert />
          </AlertIcon>
          <AlertDescription>
            Automatic transfers are off, so every row here waits for a person.
            Turn them on in Settings → Transfer API once the tunnel is up.
          </AlertDescription>
        </Alert>
      ) : null}

      <div className="flex flex-wrap gap-2">
        {TABS.map((entry) => (
          <Button
            key={entry.key}
            size="sm"
            variant={tab === entry.key ? 'primary' : 'outline'}
            onClick={() => setTab(entry.key)}
          >
            {entry.label}
            {counts[entry.key] ? ` (${counts[entry.key]})` : ''}
          </Button>
        ))}
      </div>

      {payments.isPending ? (
        <Skeleton className="h-64 w-full" />
      ) : payments.isError ? (
        <Alert variant="destructive" appearance="light">
          <AlertIcon>
            <TriangleAlert />
          </AlertIcon>
          <AlertDescription>{apiErrorMessage(payments.error)}</AlertDescription>
        </Alert>
      ) : payments.data.data.length === 0 ? (
        <Card>
          <CardContent className="py-12 text-center text-sm text-muted-foreground">
            Nothing here.
          </CardContent>
        </Card>
      ) : (
        <div className="flex flex-col gap-3">
          {payments.data.data.map((payment) => (
            <PaymentRow key={payment.id} payment={payment} />
          ))}
        </div>
      )}
    </div>
  );
}

const STATE_VARIANT: Record<string, 'success' | 'warning' | 'destructive' | 'secondary' | 'info'> = {
  pending: 'secondary',
  processing: 'info',
  pending_approval: 'warning',
  sent: 'success',
  failed: 'destructive',
  cancelled: 'secondary',
};

function PaymentRow({ payment }: { payment: PendingPayment }) {
  const queryClient = useQueryClient();
  const [manual, setManual] = useState(false);
  const [cancelling, setCancelling] = useState(false);
  const [trxId, setTrxId] = useState('');
  const [reason, setReason] = useState('');

  const invalidate = () =>
    queryClient.invalidateQueries({ queryKey: ['admin', 'pending-payments'] });

  const send = useMutation({
    mutationFn: () => sendPendingPayment(payment.id),
    onSuccess: (result) => {
      invalidate();
      // Parked is not sent, and saying so is the difference between an
      // operator waiting and an operator sending it again.
      toast.success(
        result.data.state === 'pending_approval'
          ? 'Sent — waiting for a second approver.'
          : result.data.state === 'sent'
            ? `Transferred. Reference ${result.data.trx_id ?? '—'}`
            : `Refused: ${result.data.failure_reason ?? 'unknown'}`,
      );
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const record = useMutation({
    mutationFn: () => markPendingPaymentSent(payment.id, trxId.trim()),
    onSuccess: () => {
      invalidate();
      setManual(false);
      toast.success('Recorded.');
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const cancel = useMutation({
    mutationFn: () => cancelPendingPayment(payment.id, reason.trim()),
    onSuccess: () => {
      invalidate();
      setCancelling(false);
      toast.success('Cancelled and returned to the wallet.');
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const parked = payment.state === 'pending_approval';
  const sendable = payment.state === 'pending' || payment.state === 'failed';

  return (
    <Card>
      <CardContent className="flex flex-col gap-3 py-4">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <span className="font-medium">{payment.customer_name ?? '—'}</span>
              <Badge
                variant={STATE_VARIANT[payment.state] ?? 'secondary'}
                appearance="light"
                size="sm"
              >
                {payment.state.replace('_', ' ')}
              </Badge>
              {payment.attempts > 1 ? (
                <Badge variant="secondary" appearance="ghost" size="sm">
                  {payment.attempts} attempts
                </Badge>
              ) : null}
            </div>
            <p className="text-sm text-muted-foreground">
              {payment.customer_phone ?? '—'} · {payment.bank ?? '—'}{' '}
              {payment.account}
              {payment.account_name ? ` · ${payment.account_name}` : ''}
            </p>
            <p className="text-xs text-muted-foreground/80">
              {/* The one string that identifies this transfer in our table,
                  the bank's, and on any sheet in between. */}
              Key <code>{payment.internal_ref}</code>
            </p>
          </div>
          <div className="text-end">
            <p className="text-lg font-semibold">
              {formatMoney(payment.amount_laari)}
            </p>
            {payment.trx_id ? (
              <p className="text-xs text-muted-foreground">
                Bank ref {payment.trx_id}
              </p>
            ) : null}
            {payment.approval_id ? (
              <p className="text-xs text-warning">
                {/* Named for what it is: quoting it at a bank gets nowhere. */}
                Approval queue id {payment.approval_id}
              </p>
            ) : null}
          </div>
        </div>

        {payment.failure_reason ? (
          <Alert variant={parked ? 'warning' : 'destructive'} appearance="light" size="sm">
            <AlertIcon>
              <TriangleAlert />
            </AlertIcon>
            <AlertDescription>
              {payment.failure_reason}
              {payment.error_code ? ` (${payment.error_code})` : ''}
            </AlertDescription>
          </Alert>
        ) : null}

        {parked ? (
          <p className="text-xs text-muted-foreground">
            This transfer is with a second approver. It is alive, not failed —
            do not send it again.
          </p>
        ) : null}

        {sendable ? (
          <div className="flex flex-wrap gap-2">
            <Button size="sm" disabled={send.isPending} onClick={() => send.mutate()}>
              {send.isPending ? 'Sending…' : 'Send via bank API'}
            </Button>
            <Button size="sm" variant="outline" onClick={() => setManual(true)}>
              Record a manual transfer
            </Button>
            <Button size="sm" variant="ghost" onClick={() => setCancelling(true)}>
              Cancel
            </Button>
          </div>
        ) : null}
      </CardContent>

      <Dialog open={manual} onOpenChange={setManual}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Record a manual transfer</DialogTitle>
          </DialogHeader>
          <DialogBody className="flex flex-col gap-2.5">
            <Label htmlFor={`trx-${payment.id}`}>Bank reference</Label>
            <Input
              id={`trx-${payment.id}`}
              value={trxId}
              onChange={(event) => setTrxId(event.target.value)}
              placeholder="FT99881234"
            />
            <p className="text-xs text-muted-foreground">
              For a transfer you made in the bank&apos;s own app. The customer
              sees this reference against their withdrawal.
            </p>
          </DialogBody>
          <DialogFooter>
            <Button variant="outline" onClick={() => setManual(false)}>
              Cancel
            </Button>
            <Button
              disabled={trxId.trim() === '' || record.isPending}
              onClick={() => record.mutate()}
            >
              Record
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={cancelling} onOpenChange={setCancelling}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Cancel this withdrawal</DialogTitle>
          </DialogHeader>
          <DialogBody className="flex flex-col gap-2.5">
            <Label htmlFor={`why-${payment.id}`}>Reason</Label>
            <Input
              id={`why-${payment.id}`}
              value={reason}
              onChange={(event) => setReason(event.target.value)}
            />
            <p className="text-xs text-muted-foreground">
              The money goes back to the customer&apos;s wallet, and this
              reason appears on their ledger.
            </p>
          </DialogBody>
          <DialogFooter>
            <Button variant="outline" onClick={() => setCancelling(false)}>
              Keep it
            </Button>
            <Button
              variant="destructive"
              disabled={reason.trim().length < 3 || cancel.isPending}
              onClick={() => cancel.mutate()}
            >
              Cancel and refund the wallet
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </Card>
  );
}
