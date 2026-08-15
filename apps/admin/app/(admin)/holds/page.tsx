'use client';

import { useState } from 'react';
import { listHolds, type Hold } from '@manfaa/api-client';
import { MoneyText } from '@manfaa/ui';
import { useQuery } from '@tanstack/react-query';
import { ShieldAlert, TriangleAlert } from 'lucide-react';
import { apiErrorMessage } from '@/lib/api-error';
import { formatDateTime } from '@/lib/format';
import {
  reasonCodeLabel,
  transactionOriginLabel,
  transactionStateLabel,
} from '@/lib/labels';
import { Alert, AlertDescription, AlertIcon } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Card, CardTable } from '@/components/ui/card';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
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
import { Pager } from '@/components/admin/pager';
import { RejectHoldDialog } from '@/components/holds/reject-dialog';
import { ReleaseHoldDialog } from '@/components/holds/release-dialog';

/** Matches StaleHolds::STALE_AFTER_DAYS — a review nobody has decided in a month. */
const STALE_AFTER_DAYS = 30;

const ALL = 'all';

/** No hold reason recorded at all — an older row, or one held by hand. */
const NO_REASON = 'Under review';

function ageBadge(hold: Hold) {
  if (hold.age_days === null) {
    return <span className="text-muted-foreground">—</span>;
  }
  const days = hold.age_days;
  const label = days === 1 ? '1 day' : `${days} days`;
  return days >= STALE_AFTER_DAYS ? (
    <Badge variant="destructive" appearance="light" size="sm">
      {label}
    </Badge>
  ) : (
    <span className="whitespace-nowrap">{label}</span>
  );
}

export default function HoldsPage() {
  const [reason, setReason] = useState<string>(ALL);
  const [merchantId, setMerchantId] = useState<string>(ALL);
  const [page, setPage] = useState(1);

  const query = useQuery({
    queryKey: ['admin', 'holds', { reason, merchantId, page }],
    queryFn: ({ signal }) =>
      listHolds(
        {
          reason: reason === ALL ? undefined : reason,
          merchant_id: merchantId === ALL ? undefined : Number(merchantId),
          page,
        },
        { signal },
      ),
  });

  const summary = query.data?.summary;

  const changeFilter = (apply: () => void) => {
    apply();
    setPage(1);
  };

  return (
    <div className="flex flex-col">
      <PageHeader
        title={
          <>
            Holds
            {summary && summary.total > 0 ? (
              <Badge variant="warning" appearance="light" size="sm">
                {summary.total}
              </Badge>
            ) : null}
          </>
        }
        description="Transactions under fraud or dispute review. Nothing leaves this queue on its own — a customer's cashback stays Pending and the store's settlement clock does not run until somebody decides."
        actions={
          <div className="flex flex-wrap items-center gap-2.5">
            <Select
              value={reason}
              onValueChange={(value) => changeFilter(() => setReason(value))}
            >
              <SelectTrigger className="w-52">
                <SelectValue placeholder="All reasons" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value={ALL}>All reasons</SelectItem>
                {(summary?.reasons ?? [])
                  .filter((entry) => entry.reason_code !== null)
                  .map((entry) => (
                    <SelectItem
                      key={entry.reason_code}
                      value={entry.reason_code as string}
                    >
                      {reasonCodeLabel(entry.reason_code)} ({entry.count})
                    </SelectItem>
                  ))}
              </SelectContent>
            </Select>

            <Select
              value={merchantId}
              onValueChange={(value) =>
                changeFilter(() => setMerchantId(value))
              }
            >
              <SelectTrigger className="w-52">
                <SelectValue placeholder="All stores" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value={ALL}>All stores</SelectItem>
                {(summary?.merchants ?? []).map((entry) => (
                  <SelectItem key={entry.id} value={String(entry.id)}>
                    {entry.name} ({entry.count})
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        }
      />

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
                      <TableHead>Store</TableHead>
                      <TableHead>Invoice</TableHead>
                      <TableHead className="text-end">Eligible</TableHead>
                      <TableHead className="text-end">Cashback</TableHead>
                      <TableHead className="text-end">Fee</TableHead>
                      <TableHead>Reason</TableHead>
                      <TableHead>Held for</TableHead>
                      <TableHead>Origin</TableHead>
                      <TableHead className="text-end">Actions</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {query.isPending ? (
                      Array.from({ length: 5 }).map((_, index) => (
                        <TableRow key={index}>
                          <TableCell colSpan={9}>
                            <Skeleton className="h-9 w-full" />
                          </TableCell>
                        </TableRow>
                      ))
                    ) : query.data.data.length === 0 ? (
                      <TableRow>
                        <TableCell colSpan={9} className="py-12">
                          <div className="flex flex-col items-center gap-2 text-center">
                            <ShieldAlert className="size-6 text-muted-foreground" />
                            <span className="font-medium">
                              {summary && summary.total > 0
                                ? 'No holds match these filters.'
                                : 'Nothing is under review.'}
                            </span>
                            <span className="max-w-md text-sm text-muted-foreground">
                              {summary && summary.total > 0
                                ? 'Clear the reason or store filter to see the rest of the queue.'
                                : 'Holds are opened by a person, for fraud or a dispute — backdated credits go straight to payable and never land here.'}
                            </span>
                          </div>
                        </TableCell>
                      </TableRow>
                    ) : (
                      query.data.data.map((hold) => (
                        <TableRow key={hold.id}>
                          <TableCell>
                            <div className="flex min-w-0 flex-col">
                              <span className="font-medium">
                                {hold.merchant.name}
                              </span>
                              <span className="text-xs text-muted-foreground">
                                {hold.customer === null
                                  ? 'No customer on this sale'
                                  : `${hold.customer.masked_name} · ${hold.customer.customer_code}`}
                              </span>
                            </div>
                          </TableCell>
                          <TableCell>
                            <div className="flex min-w-0 flex-col">
                              <span className="text-mono">
                                {hold.invoice_no}
                              </span>
                              <span className="text-xs text-muted-foreground">
                                {formatDateTime(hold.occurred_at)}
                              </span>
                            </div>
                          </TableCell>
                          <TableCell className="text-end">
                            <MoneyText
                              laari={hold.eligible_laari}
                              currency={hold.currency}
                            />
                          </TableCell>
                          <TableCell className="text-end">
                            <MoneyText
                              laari={hold.cashback_laari}
                              currency={hold.currency}
                            />
                          </TableCell>
                          <TableCell className="text-end">
                            <MoneyText
                              laari={hold.fee_laari + hold.fee_gst_laari}
                              currency={hold.currency}
                            />
                          </TableCell>
                          <TableCell>
                            <div className="flex flex-wrap items-center gap-1.5">
                              <span>
                                {reasonCodeLabel(hold.reason_code) ?? NO_REASON}
                              </span>
                              {hold.backdated ? (
                                <Badge
                                  variant="warning"
                                  appearance="light"
                                  size="sm"
                                >
                                  Backdated
                                </Badge>
                              ) : null}
                              {hold.has_accrual ? null : (
                                <Badge
                                  variant="secondary"
                                  appearance="light"
                                  size="sm"
                                >
                                  No accrual
                                </Badge>
                              )}
                            </div>
                            <div className="text-xs text-muted-foreground">
                              {hold.release_target.starts_clock
                                ? hold.release_target.resumes_clock
                                  ? 'Release resumes the clock where the hold froze it'
                                  : 'Release starts the 15-day clock'
                                : `Release returns it to ${transactionStateLabel(
                                    hold.release_target.state,
                                  ).toLowerCase()}`}
                            </div>
                          </TableCell>
                          <TableCell>{ageBadge(hold)}</TableCell>
                          <TableCell>
                            {transactionOriginLabel(hold.origin)}
                          </TableCell>
                          <TableCell>
                            <div className="flex items-center justify-end gap-2">
                              <ReleaseHoldDialog hold={hold} />
                              <RejectHoldDialog hold={hold} />
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

          {query.data ? (
            <Pager meta={query.data.meta} onPageChange={setPage} />
          ) : null}
        </>
      )}
    </div>
  );
}
