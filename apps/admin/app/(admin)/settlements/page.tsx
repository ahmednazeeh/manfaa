'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import {
  listAdminSettlements,
  SettlementStateSchema,
  type SettlementState,
} from '@manfaa/api-client';
import { MoneyText } from '@manfaa/ui';
import { useQuery } from '@tanstack/react-query';
import { TriangleAlert } from 'lucide-react';
import { apiErrorMessage } from '@/lib/api-error';
import { formatDateTime } from '@/lib/format';
import { Alert, AlertDescription, AlertIcon } from '@/components/ui/alert';
import { Card, CardTable } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { PageHeader } from '@/components/admin/page-header';
import { Pager } from '@/components/admin/pager';
import { SettlementStateBadge } from '@/components/admin/state-badge';

type StateFilter = SettlementState | 'all';

const TABS: { value: StateFilter; label: string }[] = [
  { value: 'all', label: 'All' },
  { value: 'payment_review', label: 'Payment review' },
  { value: 'awaiting_payment', label: 'Awaiting payment' },
  { value: 'partially_settled', label: 'Partially settled' },
  { value: 'settled', label: 'Settled' },
  { value: 'draft', label: 'Draft' },
  { value: 'cancelled', label: 'Cancelled' },
];

export default function SettlementsPage() {
  const router = useRouter();
  const [state, setState] = useState<StateFilter>('all');
  const [page, setPage] = useState(1);

  const query = useQuery({
    queryKey: ['admin', 'settlements', state, page],
    queryFn: ({ signal }) =>
      listAdminSettlements(
        { state: state === 'all' ? undefined : state, page },
        { signal },
      ),
  });

  const onTabChange = (value: string) => {
    const parsed = SettlementStateSchema.safeParse(value);
    setState(parsed.success ? parsed.data : 'all');
    setPage(1);
  };

  return (
    <div className="flex flex-col">
      <PageHeader
        title="Settlement matching queue"
        description="Merchant settlement batches by state — record claimed bank payments and match them to confirm rewards oldest-first."
      />

      <Tabs value={state} onValueChange={onTabChange} className="mb-4">
        <TabsList>
          {TABS.map((tab) => (
            <TabsTrigger key={tab.value} value={tab.value}>
              {tab.label}
            </TabsTrigger>
          ))}
        </TabsList>
      </Tabs>

      {query.isError ? (
        <Alert variant="destructive" appearance="light">
          <AlertIcon>
            <TriangleAlert />
          </AlertIcon>
          <AlertDescription>{apiErrorMessage(query.error)}</AlertDescription>
        </Alert>
      ) : (
        <Card>
          <CardTable>
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Reference</TableHead>
                    <TableHead>State</TableHead>
                    <TableHead>Funding</TableHead>
                    <TableHead className="text-end">Amount due</TableHead>
                    <TableHead className="text-end">Received</TableHead>
                    <TableHead>Due at</TableHead>
                    <TableHead>Created</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {query.isPending ? (
                    Array.from({ length: 5 }).map((_, index) => (
                      <TableRow key={index}>
                        <TableCell colSpan={7}>
                          <Skeleton className="h-6 w-full" />
                        </TableCell>
                      </TableRow>
                    ))
                  ) : query.data.data.length === 0 ? (
                    <TableRow>
                      <TableCell
                        colSpan={7}
                        className="py-10 text-center text-muted-foreground"
                      >
                        No settlements
                        {state === 'all' ? '' : ' in this state'}.
                      </TableCell>
                    </TableRow>
                  ) : (
                    query.data.data.map((settlement) => (
                      <TableRow
                        key={settlement.id}
                        className="cursor-pointer"
                        onClick={() =>
                          router.push(`/settlements/${settlement.id}`)
                        }
                      >
                        <TableCell className="font-medium">
                          {settlement.reference}
                        </TableCell>
                        <TableCell>
                          <SettlementStateBadge state={settlement.state} />
                        </TableCell>
                        <TableCell className="capitalize">
                          {settlement.funding_method}
                        </TableCell>
                        <TableCell className="text-end">
                          <MoneyText laari={settlement.amount_due_laari} />
                        </TableCell>
                        <TableCell className="text-end">
                          <MoneyText laari={settlement.amount_received_laari} />
                        </TableCell>
                        <TableCell>
                          {formatDateTime(settlement.due_at)}
                        </TableCell>
                        <TableCell>
                          {formatDateTime(settlement.created_at)}
                        </TableCell>
                      </TableRow>
                    ))
                  )}
                </TableBody>
              </Table>
            </div>
          </CardTable>
        </Card>
      )}

      {query.data ? (
        <Pager meta={query.data.meta} onPageChange={setPage} />
      ) : null}
    </div>
  );
}
