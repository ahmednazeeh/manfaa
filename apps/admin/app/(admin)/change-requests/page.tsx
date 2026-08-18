'use client';

import { useMemo, useState } from 'react';
import type {
  ChangeRequestKind,
  ChangeRequestStatus,
  MerchantChangeRequest,
} from '@manfaa/api-client';
import { useQuery } from '@tanstack/react-query';
import { Search, TriangleAlert } from 'lucide-react';
import { apiErrorMessage } from '@/lib/api-error';
import {
  CHANGE_KINDS,
  changeStoreName,
  changeTargetName,
  listChangeRequests,
} from '@/lib/change-requests';
import { formatDateTime } from '@/lib/format';
import { changeKindLabel } from '@/lib/labels';
import { Alert, AlertDescription, AlertIcon } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Card, CardTable } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
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
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { PageHeader } from '@/components/admin/page-header';
import { ChangeKindBadge } from '@/components/change-requests/change-badges';
import { ChangeRequestSheet } from '@/components/change-requests/change-request-sheet';
import { StoreLogo } from '@/components/store-reviews/store-logo';

/** The kind filter's "no filter" sentinel — Select has no empty value. */
const ALL = 'all';

const TABS: { value: ChangeRequestStatus; label: string }[] = [
  { value: 'pending', label: 'Pending' },
  { value: 'approved', label: 'Approved' },
  { value: 'rejected', label: 'Refused' },
  { value: 'superseded', label: 'Superseded' },
];

const EMPTY_COPY: Record<ChangeRequestStatus, string> = {
  pending:
    'The queue is clear — no live store is waiting on a decision. Contact numbers never queue here, so a store can still fix how it is reached without asking.',
  approved: 'Nothing has been approved yet.',
  rejected: 'Nothing has been refused.',
  superseded:
    'No request has been replaced. A store that re-submits before a decision lands leaves the earlier request here rather than deleting it.',
};

/**
 * A pending row is dated by when the store asked; a decided one by when an
 * admin decided. Superseding is not a decision — nobody reviewed it — so
 * those rows keep showing when they were asked for.
 */
function dateFor(
  status: ChangeRequestStatus,
  request: MerchantChangeRequest,
): string {
  return status === 'approved' || status === 'rejected'
    ? formatDateTime(request.reviewed_at)
    : formatDateTime(request.submitted_at);
}

function dateHeading(status: ChangeRequestStatus): string {
  return status === 'approved' || status === 'rejected'
    ? 'Decided'
    : 'Submitted';
}

/** Name, slug, branch and submitter — everything visible on the row. */
function matches(request: MerchantChangeRequest, needle: string): boolean {
  const haystack = [
    changeStoreName(request),
    request.merchant?.slug ?? '',
    changeTargetName(request),
    request.submitted_by?.name ?? '',
  ]
    .join(' ')
    .toLowerCase();

  return haystack.includes(needle);
}

/**
 * The store-CHANGE review queue (MR9) — the sibling of Store reviews. That
 * queue decides whether a store may go live at all; this one decides whether
 * a store that IS live may change what a shopper reads and trusts: its name,
 * category, channel, logo, website, its "what earns cashback" promise, and
 * its branch estate.
 *
 * Nothing in the list has taken effect. Until a superadmin approves a row,
 * the store keeps serving exactly what the drawer's "before" column shows.
 */
export default function ChangeRequestsPage() {
  const [status, setStatus] = useState<ChangeRequestStatus>('pending');
  const [kind, setKind] = useState<ChangeRequestKind | typeof ALL>(ALL);
  const [search, setSearch] = useState('');

  const query = useQuery({
    // Same key as the sidebar badge on the default (pending, all kinds)
    // view — one cache entry, one poll.
    queryKey: ['admin', 'change-requests', status, kind],
    queryFn: ({ signal }) =>
      listChangeRequests(
        { status, kind: kind === ALL ? undefined : kind },
        { signal },
      ),
  });

  const counts = query.data?.meta.counts;
  const needle = search.trim().toLowerCase();

  const rows = useMemo(() => {
    const all = query.data?.data ?? [];
    return needle === '' ? all : all.filter((row) => matches(row, needle));
  }, [query.data, needle]);

  return (
    <div className="flex flex-col">
      <PageHeader
        title="Change requests"
        description="What live stores want to change about the claims a shopper reads — name, category, channel, logo, website, the cashback promise and the branch estate. Nothing here has taken effect; the store keeps serving the “before” until a superadmin decides."
      />

      <Tabs
        value={status}
        onValueChange={(value) => setStatus(value as ChangeRequestStatus)}
        className="mb-4"
      >
        <TabsList>
          {TABS.map((tab) => (
            <TabsTrigger key={tab.value} value={tab.value}>
              {tab.label}
              {counts ? (
                <Badge
                  variant={
                    tab.value === 'pending' && counts.pending > 0
                      ? 'warning'
                      : 'secondary'
                  }
                  appearance="light"
                  size="sm"
                  className="ms-1.5"
                >
                  {counts[tab.value]}
                </Badge>
              ) : null}
            </TabsTrigger>
          ))}
        </TabsList>
      </Tabs>

      <div className="mb-4 flex flex-wrap items-center gap-2.5">
        <div className="relative w-full max-w-sm">
          <Search className="absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            className="ps-9"
            placeholder="Search store, branch or who asked…"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
          />
        </div>
        <Select
          value={kind}
          onValueChange={(value) =>
            setKind(value as ChangeRequestKind | typeof ALL)
          }
        >
          <SelectTrigger className="w-52">
            <SelectValue placeholder="All changes" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>All changes</SelectItem>
            {CHANGE_KINDS.map((value) => (
              <SelectItem key={value} value={value}>
                {changeKindLabel(value)}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

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
                    <TableHead>Store</TableHead>
                    <TableHead>Change</TableHead>
                    <TableHead>Applies to</TableHead>
                    <TableHead>Asked by</TableHead>
                    <TableHead>{dateHeading(status)}</TableHead>
                    <TableHead className="text-end">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {query.isPending ? (
                    Array.from({ length: 5 }).map((_, index) => (
                      <TableRow key={index}>
                        <TableCell colSpan={6}>
                          <Skeleton className="h-9 w-full" />
                        </TableCell>
                      </TableRow>
                    ))
                  ) : rows.length === 0 ? (
                    <TableRow>
                      <TableCell
                        colSpan={6}
                        className="py-10 text-center text-muted-foreground"
                      >
                        {needle !== ''
                          ? `Nothing on this tab matches “${search.trim()}”.`
                          : kind !== ALL
                            ? `No “${changeKindLabel(kind)}” request on this tab.`
                            : EMPTY_COPY[status]}
                      </TableCell>
                    </TableRow>
                  ) : (
                    rows.map((request) => (
                      <TableRow key={request.id}>
                        <TableCell>
                          <div className="flex items-center gap-3">
                            <StoreLogo
                              name={changeStoreName(request)}
                              logoUrl={request.merchant?.logo_url ?? null}
                            />
                            <div className="flex min-w-0 flex-col">
                              <span className="font-medium">
                                {changeStoreName(request)}
                              </span>
                              {request.merchant ? (
                                <span className="text-xs text-muted-foreground">
                                  {request.merchant.slug}
                                </span>
                              ) : null}
                            </div>
                          </div>
                        </TableCell>
                        <TableCell>
                          <ChangeKindBadge kind={request.kind} />
                        </TableCell>
                        <TableCell className="max-w-56 truncate">
                          {changeTargetName(request)}
                        </TableCell>
                        <TableCell>
                          {request.submitted_by?.name ?? (
                            <span className="text-muted-foreground">—</span>
                          )}
                        </TableCell>
                        <TableCell className="whitespace-nowrap">
                          {dateFor(status, request)}
                        </TableCell>
                        <TableCell className="text-end">
                          <ChangeRequestSheet request={request} />
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
