'use client';

import { useState } from 'react';
import { MoneyText } from '@manfaa/ui';
import { HandCoins, LoaderCircle } from 'lucide-react';
import { apiErrorMessage, useCreateSettlement, useOutstanding, useTransactions } from '@/lib/queries';
import { Button } from '@/components/ui/button';
import {
  Card,
  CardFooter,
  CardHeader,
  CardTable,
  CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { format } from 'date-fns';
import { toast } from 'sonner';
import { useRouter } from 'next/navigation';
import {
  Toolbar,
  ToolbarActions,
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
import { RoleGate } from '@/components/app/role-gate';

/**
 * Settlement builder (§10): pick outstanding (payable) transactions or
 * settle everything at once. Creates a draft batch, then hands over to the
 * settlement detail screen for submit + payment instructions.
 */
/**
 * Creating a batch is manager-or-owner work (PLAN §1) — the whole screen
 * is a settlement mutation, so it is gated rather than merely read-only.
 */
export default function NewSettlementPage() {
  return (
    <RoleGate min="manager">
      <NewSettlementScreen />
    </RoleGate>
  );
}

function NewSettlementScreen() {
  const router = useRouter();
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const outstanding = useOutstanding();
  const payable = useTransactions('payable_unfunded', page);
  const createMutation = useCreateSettlement();

  const toggle = (id: number, checked: boolean) => {
    setSelected((current) => {
      const next = new Set(current);
      if (checked) {
        next.add(id);
      } else {
        next.delete(id);
      }
      return next;
    });
  };

  const pageIds = payable.data?.data.map((transaction) => transaction.id) ?? [];
  const allOnPageSelected =
    pageIds.length > 0 && pageIds.every((id) => selected.has(id));

  const togglePage = (checked: boolean) => {
    setSelected((current) => {
      const next = new Set(current);
      for (const id of pageIds) {
        if (checked) {
          next.add(id);
        } else {
          next.delete(id);
        }
      }
      return next;
    });
  };

  const create = (body: { settle_all: true } | { ids: number[] }) => {
    createMutation.mutate(body, {
      onSuccess: (response) => {
        toast.success(`Settlement ${response.data.reference} created`);
        router.push(`/settlements/${response.data.id}`);
      },
      onError: (error) => {
        toast.error(
          apiErrorMessage(error, 'Could not create the settlement.'),
        );
      },
    });
  };

  const busy = createMutation.isPending;

  return (
    <div className="container">
      <Toolbar>
        <ToolbarHeading>
          <ToolbarPageTitle>New settlement</ToolbarPageTitle>
          <ToolbarDescription>
            {outstanding.data ? (
              <>
                Outstanding:{' '}
                <MoneyText
                  laari={outstanding.data.total.payable_laari}
                  className="font-medium"
                />{' '}
                across {outstanding.data.total.count} transaction
                {outstanding.data.total.count === 1 ? '' : 's'}
              </>
            ) : (
              'Select transactions to settle, or settle everything at once'
            )}
          </ToolbarDescription>
        </ToolbarHeading>
        <ToolbarActions>
          <Button
            variant="outline"
            disabled={busy || selected.size === 0}
            onClick={() => create({ ids: Array.from(selected) })}
          >
            {busy && <LoaderCircle className="animate-spin" />}
            Settle selected ({selected.size})
          </Button>
          <Button
            disabled={busy || outstanding.data?.total.count === 0}
            onClick={() => create({ settle_all: true })}
          >
            {busy ? <LoaderCircle className="animate-spin" /> : <HandCoins />}
            Settle all
          </Button>
        </ToolbarActions>
      </Toolbar>

      <Card className="mb-7.5">
        <CardHeader>
          <CardTitle>Payable transactions</CardTitle>
        </CardHeader>

        {payable.error ? (
          <ErrorBlock error={payable.error} />
        ) : !payable.data ? (
          <LoadingBlock lines={5} />
        ) : payable.data.data.length === 0 ? (
          <EmptyBlock>
            Nothing to settle right now — payable transactions appear here
            once their validation window closes.
          </EmptyBlock>
        ) : (
          <>
            <CardTable>
              <div className="overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead className="w-10">
                        <Checkbox
                          checked={allOnPageSelected}
                          onCheckedChange={(checked) =>
                            togglePage(checked === true)
                          }
                          aria-label="Select all on this page"
                        />
                      </TableHead>
                      <TableHead>Invoice</TableHead>
                      <TableHead>Date</TableHead>
                      <TableHead className="text-end">Cashback</TableHead>
                      <TableHead className="text-end">Fee + GST</TableHead>
                      <TableHead className="text-end">Due</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {payable.data.data.map((transaction) => (
                      <TableRow key={transaction.id}>
                        <TableCell>
                          <Checkbox
                            checked={selected.has(transaction.id)}
                            onCheckedChange={(checked) =>
                              toggle(transaction.id, checked === true)
                            }
                            aria-label={`Select invoice ${transaction.invoice_no}`}
                          />
                        </TableCell>
                        <TableCell className="font-medium text-mono">
                          {transaction.invoice_no}
                        </TableCell>
                        <TableCell className="text-secondary-foreground whitespace-nowrap">
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
                              transaction.fee_laari + transaction.fee_gst_laari
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
            <CardFooter>
              <ListPagination meta={payable.data.meta} onPageChange={setPage} />
            </CardFooter>
          </>
        )}
      </Card>
    </div>
  );
}
