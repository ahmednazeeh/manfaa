'use client';

import { MoneyText } from '@manfaa/ui';
import { cn } from '@/lib/utils';
import { useWallet } from '@/lib/queries';
import {
  Card,
  CardContent,
  CardHeader,
  CardTable,
  CardTitle,
} from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
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

export default function WalletPage() {
  const wallet = useWallet();

  return (
    <div className="container">
      <Toolbar>
        <ToolbarHeading>
          <ToolbarPageTitle>Wallet</ToolbarPageTitle>
          <ToolbarDescription>
            A settlement method — fund batches without a bank transfer
          </ToolbarDescription>
        </ToolbarHeading>
      </Toolbar>

      {wallet.error ? (
        <ErrorBlock error={wallet.error} />
      ) : (
        <div className="flex flex-col gap-5 pb-7.5">
          <Card>
            <CardContent className="flex flex-col gap-1.5 p-5">
              <span className="text-xs font-medium uppercase text-muted-foreground">
                Balance
              </span>
              {wallet.data ? (
                <MoneyText
                  laari={wallet.data.balance_laari}
                  className="text-2xl font-semibold text-mono"
                />
              ) : (
                <Skeleton className="h-8 w-40 rounded-md" />
              )}
              <span className="text-sm text-muted-foreground">
                Top-ups are recorded by our team when your transfer arrives.
              </span>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Movements</CardTitle>
            </CardHeader>
            {!wallet.data ? (
              <LoadingBlock lines={4} />
            ) : (wallet.data.transactions?.length ?? 0) === 0 ? (
              <EmptyBlock>No wallet movements yet.</EmptyBlock>
            ) : (
              <CardTable>
                <div className="overflow-x-auto">
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>Date</TableHead>
                        <TableHead>Type</TableHead>
                        <TableHead>Description</TableHead>
                        <TableHead className="text-end">Amount</TableHead>
                        <TableHead className="text-end">Balance after</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {(wallet.data.transactions ?? []).map((movement) => (
                        <TableRow key={movement.id}>
                          <TableCell className="text-secondary-foreground whitespace-nowrap">
                            {format(
                              new Date(movement.created_at),
                              'dd MMM yyyy, HH:mm',
                            )}
                          </TableCell>
                          <TableCell className="capitalize">
                            {movement.type.replaceAll('_', ' ')}
                          </TableCell>
                          <TableCell className="text-secondary-foreground">
                            {movement.description ?? '—'}
                          </TableCell>
                          <TableCell
                            className={cn(
                              'text-end font-medium',
                              movement.amount_laari < 0
                                ? 'text-destructive'
                                : 'text-green-600 dark:text-green-500',
                            )}
                          >
                            <MoneyText laari={movement.amount_laari} />
                          </TableCell>
                          <TableCell className="text-end">
                            <MoneyText laari={movement.balance_after_laari} />
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
              </CardTable>
            )}
          </Card>
        </div>
      )}
    </div>
  );
}
