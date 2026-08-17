'use client';

import { useEffect, useState } from 'react';
import { listAdminCustomers } from '@manfaa/api-client';
import { useQuery } from '@tanstack/react-query';
import { Search, TriangleAlert } from 'lucide-react';
import { apiErrorMessage } from '@/lib/api-error';
import { formatDateTime } from '@/lib/format';
import { Alert, AlertDescription, AlertIcon } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Card, CardTable } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Pager } from '@/components/admin/pager';
import { PageHeader } from '@/components/admin/page-header';
import { CustomerStatusBadge } from '@/components/admin/state-badge';
import { CustomerSheet } from '@/components/customers/customer-sheet';

/**
 * The customer account register — the support surface. Search takes whatever
 * the caller reads out: a name, a phone in any typed form (seven local
 * digits, +960, partial), or the 6-digit customer code; the server folds
 * phones into the stored shape before matching. View opens the full record
 * with the superadmin rescue actions (edit incl. phone change, web password
 * reset, enable/disable).
 */
export default function CustomersPage() {
  const [input, setInput] = useState('');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);

  // Debounced: the query follows the keystrokes without firing per key.
  useEffect(() => {
    const handle = setTimeout(() => {
      setSearch(input.trim());
      setPage(1);
    }, 300);
    return () => clearTimeout(handle);
  }, [input]);

  const query = useQuery({
    queryKey: ['admin', 'customers', search, page],
    queryFn: ({ signal }) =>
      listAdminCustomers(
        { q: search === '' ? undefined : search, page },
        { signal },
      ),
  });

  return (
    <div className="flex flex-col">
      <PageHeader
        title="Customers"
        description="Find an account by name, phone or code. View opens the full record; account changes (phone, password, disable) are superadmin-only."
      />

      <div className="relative mb-4 max-w-sm">
        <Search className="absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
        <Input
          className="ps-9"
          placeholder="Search name, phone or customer code…"
          value={input}
          onChange={(event) => setInput(event.target.value)}
        />
      </div>

      {query.isError ? (
        <Alert variant="destructive" appearance="light">
          <AlertIcon>
            <TriangleAlert />
          </AlertIcon>
          <AlertDescription>{apiErrorMessage(query.error)}</AlertDescription>
        </Alert>
      ) : (
        <>
          <Card>
            <CardTable>
              <div className="overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Code</TableHead>
                      <TableHead>Name</TableHead>
                      <TableHead>Phone</TableHead>
                      <TableHead>Status</TableHead>
                      <TableHead>Payout account</TableHead>
                      <TableHead>Joined</TableHead>
                      <TableHead className="text-end">Actions</TableHead>
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
                          {search === ''
                            ? 'No customers yet.'
                            : 'No customers match this search.'}
                        </TableCell>
                      </TableRow>
                    ) : (
                      query.data.data.map((customer) => (
                        <TableRow key={customer.id}>
                          <TableCell className="font-mono text-sm">
                            {customer.customer_code}
                          </TableCell>
                          <TableCell className="font-medium">
                            {customer.name}
                          </TableCell>
                          <TableCell dir="ltr" className="font-mono text-sm">
                            {customer.phone}
                          </TableCell>
                          <TableCell>
                            <CustomerStatusBadge status={customer.status} />
                          </TableCell>
                          <TableCell>
                            {customer.has_payout_account ? (
                              <Badge
                                variant="success"
                                appearance="light"
                                size="sm"
                              >
                                On file
                              </Badge>
                            ) : (
                              <Badge
                                variant="secondary"
                                appearance="ghost"
                                size="sm"
                              >
                                None
                              </Badge>
                            )}
                          </TableCell>
                          <TableCell>
                            {formatDateTime(customer.created_at)}
                          </TableCell>
                          <TableCell className="text-end">
                            <CustomerSheet customer={customer} />
                          </TableCell>
                        </TableRow>
                      ))
                    )}
                  </TableBody>
                </Table>
              </div>
            </CardTable>
          </Card>
          {query.data !== undefined ? (
            <Pager meta={query.data.meta} onPageChange={setPage} />
          ) : null}
        </>
      )}
    </div>
  );
}
