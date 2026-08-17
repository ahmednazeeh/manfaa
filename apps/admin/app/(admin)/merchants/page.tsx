'use client';

import { listAdminMerchants } from '@manfaa/api-client';
import { MoneyText } from '@manfaa/ui';
import { useQuery } from '@tanstack/react-query';
import { TriangleAlert } from 'lucide-react';
import { apiErrorMessage } from '@/lib/api-error';
import { formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
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
import { PageHeader } from '@/components/admin/page-header';
import { MerchantStatusBadge } from '@/components/admin/state-badge';
import { FeaturedToggle } from '@/components/merchants/featured-toggle';
import { MerchantSheet } from '@/components/merchants/merchant-sheet';
import { NoticesSheet } from '@/components/merchants/notices-sheet';

export default function MerchantsPage() {
  const query = useQuery({
    queryKey: ['admin', 'merchants'],
    queryFn: ({ signal }) => listAdminMerchants({ signal }),
  });

  return (
    <div className="flex flex-col">
      <PageHeader
        title="Merchants"
        description="Standing, profile and controls per merchant. Suspension at day 16 is automatic — the only credit control; View opens the full record with the superadmin actions."
      />

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
                    <TableHead>Merchant</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead className="text-center">Featured</TableHead>
                    <TableHead className="text-end">Open payables</TableHead>
                    <TableHead className="text-end">Outstanding</TableHead>
                    <TableHead className="text-end">Overdue</TableHead>
                    <TableHead>Oldest due</TableHead>
                    <TableHead className="text-end">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {query.isPending ? (
                    Array.from({ length: 5 }).map((_, index) => (
                      <TableRow key={index}>
                        <TableCell colSpan={8}>
                          <Skeleton className="h-6 w-full" />
                        </TableCell>
                      </TableRow>
                    ))
                  ) : query.data.data.length === 0 ? (
                    <TableRow>
                      <TableCell
                        colSpan={8}
                        className="py-10 text-center text-muted-foreground"
                      >
                        No merchants found.
                      </TableCell>
                    </TableRow>
                  ) : (
                    query.data.data.map((merchant) => (
                      <TableRow key={merchant.id}>
                        <TableCell>
                          <div className="flex flex-col">
                            <span className="font-medium">{merchant.name}</span>
                            <span className="text-xs text-muted-foreground">
                              {merchant.slug}
                            </span>
                          </div>
                        </TableCell>
                        <TableCell>
                          <MerchantStatusBadge status={merchant.status} />
                        </TableCell>
                        <TableCell className="text-center">
                          <FeaturedToggle merchant={merchant} />
                        </TableCell>
                        <TableCell className="text-end">
                          {merchant.open_payable_count}
                        </TableCell>
                        <TableCell className="text-end">
                          <MoneyText laari={merchant.outstanding_laari} />
                        </TableCell>
                        <TableCell
                          className={cn(
                            'text-end',
                            merchant.overdue_laari > 0 &&
                              'font-semibold text-destructive',
                          )}
                        >
                          <MoneyText laari={merchant.overdue_laari} />
                        </TableCell>
                        <TableCell>
                          {formatDateTime(merchant.oldest_due_at)}
                        </TableCell>
                        <TableCell className="text-end">
                          <div className="flex items-center justify-end gap-1.5">
                            <MerchantSheet merchant={merchant} />
                            <NoticesSheet merchant={merchant} />
                          </div>
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
    </div>
  );
}
