'use client';

import { useState } from 'react';
import {
  listOrderPayments,
  refuseOrderPayment,
  verifyOrderPayment,
  type OrderPayment,
} from '@manfaa/api-client';
import { formatMoney } from '@manfaa/ui';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Bot, Download, TriangleAlert } from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/lib/api-error';
import { formatDateTime } from '@/lib/format';
import { Alert, AlertDescription, AlertIcon } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { PageHeader } from '@/components/admin/page-header';

/**
 * Order payments (PLAN-marketplace.md §3.4, MP6).
 *
 * A customer transferred to our account and uploaded proof; nothing reaches
 * the shops until somebody has looked. Same discipline as settlement
 * receipts, and for the same reason: a screenshot is not a bank statement.
 */
const TABS = [
  { key: 'proof_submitted', label: 'Waiting' },
  { key: 'awaiting_proof', label: 'No proof yet' },
  { key: 'verified', label: 'Verified' },
  { key: 'refused', label: 'Refused' },
  { key: 'all', label: 'All' },
] as const;

export default function OrderPaymentsPage() {
  const [tab, setTab] = useState<string>('proof_submitted');

  const payments = useQuery({
    queryKey: ['admin', 'order-payments', tab],
    queryFn: ({ signal }) => listOrderPayments(tab, { signal }),
  });

  return (
    <div className="flex flex-col gap-5">
      <PageHeader
        title="Order payments"
        description="Marketplace orders paid by bank transfer. The shops see nothing until the money is verified."
      />

      <div className="flex flex-wrap gap-2">
        {TABS.map((entry) => (
          <Button
            key={entry.key}
            size="sm"
            variant={tab === entry.key ? 'primary' : 'outline'}
            onClick={() => setTab(entry.key)}
          >
            {entry.label}
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
            <PaymentCard key={payment.id} payment={payment} />
          ))}
        </div>
      )}
    </div>
  );
}

function PaymentCard({ payment }: { payment: OrderPayment }) {
  const queryClient = useQueryClient();
  const [refusing, setRefusing] = useState(false);
  const [reason, setReason] = useState('');

  const invalidate = () =>
    queryClient.invalidateQueries({ queryKey: ['admin', 'order-payments'] });

  const verify = useMutation({
    mutationFn: () => verifyOrderPayment(payment.id),
    onSuccess: () => {
      invalidate();
      toast.success('Verified. The shops can start work.');
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const refuse = useMutation({
    mutationFn: () => refuseOrderPayment(payment.id, reason.trim()),
    onSuccess: () => {
      invalidate();
      setRefusing(false);
      toast.success('Refused. The customer can upload another receipt.');
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  return (
    <Card>
      <CardContent className="flex flex-col gap-3 py-4">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <div className="flex items-center gap-2">
              <span className="font-medium">{payment.reference}</span>
              <Badge
                variant={
                  payment.payment_state === 'verified'
                    ? 'success'
                    : payment.payment_state === 'refused'
                      ? 'destructive'
                      : payment.payment_state === 'proof_submitted'
                        ? 'warning'
                        : 'secondary'
                }
                appearance="light"
                size="sm"
              >
                {payment.payment_state.replace('_', ' ')}
              </Badge>
              <Badge variant="secondary" appearance="ghost" size="sm">
                {payment.payment_method.toUpperCase()}
              </Badge>
              {payment.auto_verified ? (
                // Nobody signed for this one, so the screen has to say so.
                <Badge variant="info" appearance="light" size="sm">
                  <Bot className="size-3" />
                  Matched automatically
                </Badge>
              ) : null}
              {payment.payment_state === 'proof_submitted' &&
              payment.poll_until &&
              new Date(payment.poll_until) > new Date() ? (
                <Badge variant="secondary" appearance="light" size="sm">
                  Watching the bank
                </Badge>
              ) : null}
            </div>
            <p className="text-sm text-muted-foreground">
              {payment.customer_name ?? '—'} · {payment.customer_phone ?? '—'}
            </p>
            <p className="text-xs text-muted-foreground/80">
              {payment.stores.filter(Boolean).join(', ')}
            </p>
            {payment.proof_submitted_at ? (
              <p className="text-xs text-muted-foreground/80">
                Proof uploaded {formatDateTime(payment.proof_submitted_at)}
              </p>
            ) : null}
            {payment.matched_trx_id ? (
              // What the bank actually said, not our conclusion about it —
              // an operator checking this later needs the bank's own words.
              <p className="text-xs text-muted-foreground/80">
                Bank reference{' '}
                <code className="text-[11px]">{payment.matched_trx_id}</code>
                {payment.matched_payer_name
                  ? ` · paid by ${payment.matched_payer_name}`
                  : ''}
                {payment.matched_score !== null
                  ? ` · name score ${payment.matched_score}`
                  : ''}
              </p>
            ) : null}
          </div>
          <p className="text-lg font-semibold">
            {formatMoney(payment.total_payable_laari)}
          </p>
        </div>

        <div className="flex flex-wrap gap-2">
          {payment.has_receipt ? (
            <Button size="sm" variant="outline" asChild>
              <a href={`/api/admin/marketplace/payments/${payment.id}/receipt`}>
                <Download className="size-4" />
                Open receipt
              </a>
            </Button>
          ) : (
            <span className="text-sm text-muted-foreground">
              No receipt uploaded yet.
            </span>
          )}

          {payment.payment_state === 'proof_submitted' ? (
            <>
              <Button size="sm" disabled={verify.isPending} onClick={() => verify.mutate()}>
                {verify.isPending ? 'Verifying…' : 'Verify payment'}
              </Button>
              <Button size="sm" variant="outline" onClick={() => setRefusing(true)}>
                Refuse
              </Button>
            </>
          ) : null}
        </div>
      </CardContent>

      <Dialog open={refusing} onOpenChange={setRefusing}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Refuse this payment</DialogTitle>
          </DialogHeader>
          <DialogBody className="flex flex-col gap-2.5">
            <Label htmlFor={`pay-why-${payment.id}`}>Reason</Label>
            <Input
              id={`pay-why-${payment.id}`}
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              placeholder="The transfer amount does not match the order."
            />
            <p className="text-xs text-muted-foreground">
              The order goes back to awaiting a receipt, not to the bin — a
              wrong screenshot is a fixable mistake, and cancelling would throw
              away a basket somebody built.
            </p>
          </DialogBody>
          <DialogFooter>
            <Button variant="outline" onClick={() => setRefusing(false)}>
              Cancel
            </Button>
            <Button
              variant="destructive"
              disabled={reason.trim().length < 3 || refuse.isPending}
              onClick={() => refuse.mutate()}
            >
              Refuse
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </Card>
  );
}
