'use client';

import { useState } from 'react';
import {
  acceptMerchantOrder,
  advanceMerchantOrder,
  amendMerchantOrder,
  rejectMerchantOrder,
  type MerchantOrder,
} from '@manfaa/api-client';
import { formatMoney } from '@manfaa/ui';
import { toast } from 'sonner';
import { apiErrorMessage, useMerchantOrders, useOrderAction } from '@/lib/queries';
import { useTranslation } from 'react-i18next';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
import { Separator } from '@/components/ui/separator';
import { ScreenLoader } from '@/components/screen-loader';

/**
 * The shop's order queue (`Orders.png`, `Order Details.png`).
 *
 * Everything the app's Orders tab does, plus the amendment flow — reducing
 * an order when the shelf disagrees with the basket — which is desktop work
 * by nature.
 */
const TABS = [
  { key: 'new', label: 'New' },
  { key: 'preparing', label: 'Preparing' },
  { key: 'ready', label: 'Ready' },
  { key: 'completed', label: 'Completed' },
] as const;

const NEXT_STATE: Record<string, { to: string; label: string } | undefined> = {
  accepted: { to: 'preparing', label: 'Start preparing' },
  preparing: { to: 'ready', label: 'Mark ready' },
  ready: { to: 'out_for_delivery', label: 'Out for delivery' },
  out_for_delivery: { to: 'delivered', label: 'Mark delivered' },
};

export default function MarketplaceOrdersPage() {
  const [tab, setTab] = useState<string>('new');
  const orders = useMerchantOrders(tab);

  return (
    <div className="flex flex-col gap-5">
      <div className="flex flex-wrap items-center gap-2">
        {TABS.map((entry) => (
          <Button
            key={entry.key}
            size="sm"
            variant={tab === entry.key ? 'primary' : 'outline'}
            onClick={() => setTab(entry.key)}
          >
            {entry.label}
            {entry.key === 'new' && orders.data?.meta.new_count
              ? ` (${orders.data.meta.new_count})`
              : ''}
          </Button>
        ))}
      </div>

      {orders.isPending ? (
        <ScreenLoader />
      ) : orders.isError ? (
        <Alert variant="destructive" appearance="light">
          <AlertDescription>{apiErrorMessage(orders.error, 'Could not load orders.')}</AlertDescription>
        </Alert>
      ) : orders.data.data.length === 0 ? (
        <Card>
          <CardContent className="py-12 text-center text-sm text-muted-foreground">
            Nothing here.
          </CardContent>
        </Card>
      ) : (
        <div className="flex flex-col gap-3">
          {orders.data.data.map((order) => (
            <OrderCard key={order.id} order={order} />
          ))}
        </div>
      )}
    </div>
  );
}

function OrderCard({ order }: { order: MerchantOrder }) {
  const { t } = useTranslation();
  const action = useOrderAction();
  const [rejecting, setRejecting] = useState(false);
  const [amending, setAmending] = useState(false);
  const [reason, setReason] = useState('');

  const run = (call: () => Promise<unknown>, success: string) =>
    action.mutate(call, {
      onSuccess: () => toast.success(success),
      onError: (error) => toast.error(apiErrorMessage(error, t('common.errorGeneric'))),
    });

  const next = NEXT_STATE[order.state];
  const paid = order.payment_state === 'verified';

  return (
    <Card>
      <CardContent className="flex flex-col gap-4 py-4">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <div className="flex flex-wrap items-center gap-2">
              <span className="font-medium">{order.reference}</span>
              <Badge variant="secondary" appearance="light" size="sm">
                {order.state.replace(/_/g, ' ')}
              </Badge>
              <Badge
                variant={paid ? 'success' : 'warning'}
                appearance="light"
                size="sm"
              >
                {paid ? 'Paid' : (order.payment_state ?? '').replace(/_/g, ' ')}
              </Badge>
              <Badge variant="secondary" appearance="ghost" size="sm">
                {order.fulfilment}
              </Badge>
            </div>
            <p className="text-sm text-muted-foreground">
              {order.customer.name ?? '—'} · {order.customer.phone ?? '—'}
            </p>
            {order.pickup_code ? (
              <p className="text-sm">
                Pickup code <strong>{order.pickup_code}</strong>
              </p>
            ) : null}
          </div>
          <div className="text-end">
            <p className="text-lg font-semibold">{formatMoney(order.subtotal_laari)}</p>
            <p className="text-xs text-muted-foreground">
              You receive {formatMoney(order.payable_to_merchant_laari)}
            </p>
          </div>
        </div>

        {/* A shop must not start work on an order nobody has paid for. */}
        {!paid && order.state === 'new' ? (
          <Alert variant="warning" appearance="light" size="sm">
            <AlertDescription>
              This order is not paid yet. Manfaa verifies the transfer before
              you need to do anything.
            </AlertDescription>
          </Alert>
        ) : null}

        <Separator />

        <div className="flex flex-col gap-1.5">
          {order.items.map((item) => (
            <div key={item.id} className="flex items-center justify-between gap-3 text-sm">
              <span className={item.fulfilled_qty === 0 ? 'line-through text-muted-foreground' : ''}>
                {item.name}
              </span>
              <span className="flex items-center gap-2">
                {item.amended ? (
                  <>
                    <span className="text-muted-foreground line-through">×{item.qty}</span>
                    <span className="font-medium">×{item.fulfilled_qty}</span>
                    <span className="text-xs text-warning">
                      refund {formatMoney(item.refund_laari)}
                    </span>
                  </>
                ) : (
                  <span>×{item.qty}</span>
                )}
                <span className="w-24 text-end">{formatMoney(item.line_total_laari)}</span>
              </span>
            </div>
          ))}
        </div>

        {order.reject_reason ? (
          <Alert variant="destructive" appearance="light" size="sm">
            <AlertDescription>{order.reject_reason}</AlertDescription>
          </Alert>
        ) : null}

        <div className="flex flex-wrap gap-2">
          {order.state === 'new' ? (
            <>
              <Button
                size="sm"
                disabled={action.isPending}
                onClick={() => run(() => acceptMerchantOrder(order.id), 'Accepted.')}
              >
                Accept
              </Button>
              <Button size="sm" variant="outline" onClick={() => setRejecting(true)}>
                Reject
              </Button>
            </>
          ) : null}

          {next ? (
            <Button
              size="sm"
              disabled={action.isPending}
              onClick={() => run(() => advanceMerchantOrder(order.id, next.to), next.label)}
            >
              {next.label}
            </Button>
          ) : null}

          {['accepted', 'preparing'].includes(order.state) ? (
            <Button size="sm" variant="outline" onClick={() => setAmending(true)}>
              Something is out of stock
            </Button>
          ) : null}
        </div>
      </CardContent>

      <Dialog open={rejecting} onOpenChange={setRejecting}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Reject this order</DialogTitle>
          </DialogHeader>
          <DialogBody className="flex flex-col gap-2.5">
            <Label htmlFor={`why-${order.id}`}>Reason</Label>
            <Input
              id={`why-${order.id}`}
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              placeholder="Closed for a family emergency."
            />
            <p className="text-xs text-muted-foreground">
              The customer is told, and refunded in full to their Manfaa
              wallet straight away.
            </p>
          </DialogBody>
          <DialogFooter>
            <Button variant="outline" onClick={() => setRejecting(false)}>
              Keep it
            </Button>
            <Button
              variant="destructive"
              disabled={reason.trim().length < 3}
              onClick={() => {
                run(() => rejectMerchantOrder(order.id, reason.trim()), 'Rejected.');
                setRejecting(false);
              }}
            >
              Reject
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <AmendDialog
        order={order}
        open={amending}
        onOpenChange={setAmending}
        onDone={(lines, why) => {
          run(
            () => amendMerchantOrder(order.id, lines, why),
            'Order updated and the customer refunded.',
          );
          setAmending(false);
        }}
      />
    </Card>
  );
}

/**
 * Reducing an order. Only ever DOWN — the customer authorised an amount and
 * paid it to us, so anything upward would be a new order.
 */
function AmendDialog({
  order,
  open,
  onOpenChange,
  onDone,
}: {
  order: MerchantOrder;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onDone: (
    lines: { suborder_item_id: number; fulfilled_qty: number }[],
    reason: string,
  ) => void;
}) {
  const [quantities, setQuantities] = useState<Record<number, number>>(() =>
    Object.fromEntries(order.items.map((item) => [item.id, item.fulfilled_qty])),
  );
  const [reason, setReason] = useState('out_of_stock');

  const refund = order.items.reduce((sum, item) => {
    const next = quantities[item.id] ?? item.fulfilled_qty;

    return sum + (item.fulfilled_qty - next) * item.unit_price_laari;
  }, 0);

  const remaining = order.items.reduce(
    (sum, item) => sum + (quantities[item.id] ?? item.fulfilled_qty),
    0,
  );

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>What can you supply?</DialogTitle>
        </DialogHeader>
        <DialogBody className="flex flex-col gap-4">
          {order.items.map((item) => (
            <div key={item.id} className="flex items-center justify-between gap-3">
              <div className="min-w-0">
                <p className="text-sm">{item.name}</p>
                <p className="text-xs text-muted-foreground">
                  Ordered ×{item.qty}
                </p>
              </div>
              <Input
                inputMode="numeric"
                className="w-20"
                value={quantities[item.id] ?? item.fulfilled_qty}
                onChange={(event) => {
                  const next = Number(event.target.value);

                  setQuantities({
                    ...quantities,
                    // Never above what was ordered: upward is a new order.
                    [item.id]: Number.isFinite(next)
                      ? Math.max(0, Math.min(item.fulfilled_qty, next))
                      : 0,
                  });
                }}
              />
            </div>
          ))}

          <div className="flex flex-col gap-2">
            <Label htmlFor={`reason-${order.id}`}>Why</Label>
            <select
              id={`reason-${order.id}`}
              className="h-9 rounded-md border border-input bg-background px-3 text-sm"
              value={reason}
              onChange={(event) => setReason(event.target.value)}
            >
              <option value="out_of_stock">Out of stock</option>
              <option value="damaged">Damaged</option>
              <option value="customer_request">Customer asked</option>
              <option value="other">Other</option>
            </select>
          </div>

          {refund > 0 ? (
            <Alert variant="warning" appearance="light" size="sm">
              <AlertTitle>Refunding {formatMoney(refund)}</AlertTitle>
              <AlertDescription>
                Goes back to the customer&apos;s wallet at once, and they are
                told what changed. Your payout and the cashback are recomputed
                on what you actually supply — the delivery fee does not change.
              </AlertDescription>
            </Alert>
          ) : null}

          {remaining === 0 ? (
            <Alert variant="destructive" appearance="light" size="sm">
              <AlertDescription>
                That leaves nothing to fulfil. Reject the order instead, so the
                customer gets their delivery fee back too.
              </AlertDescription>
            </Alert>
          ) : null}
        </DialogBody>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button
            disabled={refund === 0 || remaining === 0}
            onClick={() =>
              onDone(
                order.items
                  .filter((item) => (quantities[item.id] ?? item.fulfilled_qty) !== item.fulfilled_qty)
                  .map((item) => ({
                    suborder_item_id: item.id,
                    fulfilled_qty: quantities[item.id] ?? item.fulfilled_qty,
                  })),
                reason,
              )
            }
          >
            Update and refund
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
