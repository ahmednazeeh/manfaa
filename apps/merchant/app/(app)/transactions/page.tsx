'use client';

import { useState } from 'react';
import {
  TransactionStateSchema,
  type TransactionState,
} from '@manfaa/api-client';
import { MoneyText } from '@manfaa/ui';
import { useTransactions } from '@/lib/queries';
import {
  Card,
  CardFooter,
  CardHeader,
  CardTable,
  CardTitle,
} from '@/components/ui/card';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { format } from 'date-fns';
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
import { TransactionStateBadge } from '@/components/app/state-badge';

const STATE_LABELS: Record<TransactionState, string> = {
  tracked: 'Tracked',
  awaiting_validation: 'Awaiting validation',
  payable_unfunded: 'Payable',
  on_hold: 'On hold',
  confirmed: 'Confirmed',
  paid: 'Paid',
  reversed: 'Reversed',
  written_off: 'Written off',
};

export default function TransactionsPage() {
  const [state, setState] = useState<TransactionState | 'all'>('all');
  const [page, setPage] = useState(1);
  const transactions = useTransactions(state, page);

  const handleStateChange = (value: string) => {
    setState(value as TransactionState | 'all');
    setPage(1);
  };

  return (
    <div className="container">
      <Toolbar>
        <ToolbarHeading>
          <ToolbarPageTitle>Transactions</ToolbarPageTitle>
          <ToolbarDescription>
            Every cashback transaction recorded for your store
          </ToolbarDescription>
        </ToolbarHeading>
      </Toolbar>

      <Card className="mb-7.5">
        <CardHeader>
          <CardTitle>
            {transactions.data ? `${transactions.data.meta.total} transactions` : 'Transactions'}
          </CardTitle>
          <Select value={state} onValueChange={handleStateChange}>
            <SelectTrigger className="w-48" aria-label="Filter by state">
              <SelectValue placeholder="All states" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All states</SelectItem>
              {TransactionStateSchema.options.map((option) => (
                <SelectItem key={option} value={option}>
                  {STATE_LABELS[option]}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </CardHeader>

        {transactions.error ? (
          <ErrorBlock error={transactions.error} />
        ) : !transactions.data ? (
          <LoadingBlock lines={5} />
        ) : transactions.data.data.length === 0 ? (
          <EmptyBlock>No transactions match this filter.</EmptyBlock>
        ) : (
          <>
            <CardTable>
              <div className="overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Invoice</TableHead>
                      <TableHead>Date</TableHead>
                      <TableHead>State</TableHead>
                      <TableHead className="text-end">Eligible</TableHead>
                      <TableHead className="text-end">Cashback</TableHead>
                      <TableHead className="text-end">Fee + GST</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {transactions.data.data.map((transaction) => (
                      <TableRow key={transaction.id}>
                        <TableCell className="font-medium text-mono">
                          {transaction.invoice_no}
                          <div className="text-xs font-normal text-muted-foreground capitalize">
                            {transaction.origin.replaceAll('_', ' ')}
                          </div>
                        </TableCell>
                        <TableCell className="text-secondary-foreground whitespace-nowrap">
                          {format(
                            new Date(transaction.occurred_at),
                            'dd MMM yyyy, HH:mm',
                          )}
                        </TableCell>
                        <TableCell>
                          <TransactionStateBadge state={transaction.state} />
                          {transaction.reason_code && (
                            <div className="text-xs text-muted-foreground mt-1">
                              {transaction.reason_code}
                            </div>
                          )}
                        </TableCell>
                        <TableCell className="text-end">
                          <MoneyText laari={transaction.eligible_laari} />
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
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            </CardTable>
            <CardFooter>
              <ListPagination
                meta={transactions.data.meta}
                onPageChange={setPage}
              />
            </CardFooter>
          </>
        )}
      </Card>
    </div>
  );
}
