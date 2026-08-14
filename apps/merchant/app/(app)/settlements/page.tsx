'use client';

import { useState } from 'react';
import { MoneyText } from '@manfaa/ui';
import { Plus } from 'lucide-react';
import { useSettlements } from '@/lib/queries';
import { Button } from '@/components/ui/button';
import {
  Card,
  CardFooter,
  CardHeader,
  CardTable,
  CardTitle,
} from '@/components/ui/card';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { format } from 'date-fns';
import Link from 'next/link';
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
import { SettlementStateBadge } from '@/components/app/state-badge';

export default function SettlementsPage() {
  const [page, setPage] = useState(1);
  const settlements = useSettlements(page);

  return (
    <div className="container">
      <Toolbar>
        <ToolbarHeading>
          <ToolbarPageTitle>Settlements</ToolbarPageTitle>
          <ToolbarDescription>
            Batches of outstanding cashback and fees you have settled or are
            settling
          </ToolbarDescription>
        </ToolbarHeading>
        <ToolbarActions>
          <Button asChild>
            <Link href="/settlements/new">
              <Plus />
              New settlement
            </Link>
          </Button>
        </ToolbarActions>
      </Toolbar>

      <Card className="mb-7.5">
        <CardHeader>
          <CardTitle>
            {settlements.data
              ? `${settlements.data.meta.total} settlements`
              : 'Settlements'}
          </CardTitle>
        </CardHeader>

        {settlements.error ? (
          <ErrorBlock error={settlements.error} />
        ) : !settlements.data ? (
          <LoadingBlock lines={5} />
        ) : settlements.data.data.length === 0 ? (
          <EmptyBlock>
            No settlements yet — build one from your outstanding transactions.
          </EmptyBlock>
        ) : (
          <>
            <CardTable>
              <div className="overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Reference</TableHead>
                      <TableHead>Created</TableHead>
                      <TableHead>State</TableHead>
                      <TableHead className="text-end">Cashback</TableHead>
                      <TableHead className="text-end">Fees</TableHead>
                      <TableHead className="text-end">Amount due</TableHead>
                      <TableHead className="text-end">Received</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {settlements.data.data.map((settlement) => (
                      <TableRow key={settlement.id}>
                        <TableCell>
                          <Link
                            href={`/settlements/${settlement.id}`}
                            className="font-medium text-mono hover:text-primary"
                          >
                            {settlement.reference}
                          </Link>
                        </TableCell>
                        <TableCell className="text-secondary-foreground whitespace-nowrap">
                          {format(
                            new Date(settlement.created_at),
                            'dd MMM yyyy',
                          )}
                        </TableCell>
                        <TableCell>
                          <SettlementStateBadge state={settlement.state} />
                        </TableCell>
                        <TableCell className="text-end">
                          <MoneyText laari={settlement.cashback_total_laari} />
                        </TableCell>
                        <TableCell className="text-end">
                          <MoneyText
                            laari={
                              settlement.fee_total_laari +
                              settlement.fee_gst_total_laari
                            }
                          />
                        </TableCell>
                        <TableCell className="text-end font-medium">
                          <MoneyText laari={settlement.amount_due_laari} />
                        </TableCell>
                        <TableCell className="text-end">
                          <MoneyText laari={settlement.amount_received_laari} />
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            </CardTable>
            <CardFooter>
              <ListPagination
                meta={settlements.data.meta}
                onPageChange={setPage}
              />
            </CardFooter>
          </>
        )}
      </Card>
    </div>
  );
}
