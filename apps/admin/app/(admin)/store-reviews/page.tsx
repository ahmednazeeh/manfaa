'use client';

import { useMemo, useState } from 'react';
import {
  formatBpPercent,
  listStoreCategories,
  listStoreReviews,
  type StoreReview,
  type StoreReviewState,
} from '@manfaa/api-client';
import { useQuery } from '@tanstack/react-query';
import { TriangleAlert } from 'lucide-react';
import { apiErrorMessage } from '@/lib/api-error';
import { formatDateTime } from '@/lib/format';
import { Alert, AlertDescription, AlertIcon } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
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
import { ChannelBadge } from '@/components/store-reviews/channel-badge';
import { ReviewSheet } from '@/components/store-reviews/review-sheet';
import { StoreLogo } from '@/components/store-reviews/store-logo';

const TABS: { value: StoreReviewState; label: string }[] = [
  { value: 'pending_review', label: 'Pending review' },
  { value: 'rejected', label: 'Rejected' },
  { value: 'draft', label: 'Draft' },
];

const EMPTY_COPY: Record<StoreReviewState, string> = {
  pending_review: 'No stores are waiting for review.',
  rejected:
    'No rejected stores. Rejected stores return here until their owner fixes the wizard and resubmits.',
  draft:
    'No stores are mid-wizard. Drafts are read-only visibility — owners submit them for review themselves.',
};

/** The queue lists pending stores oldest-submission-first; the date column follows the tab. */
function dateColumn(state: StoreReviewState, review: StoreReview): string {
  if (state === 'rejected') {
    return formatDateTime(review.rejected_at);
  }
  if (state === 'draft') {
    return formatDateTime(review.created_at);
  }
  return formatDateTime(review.submitted_at);
}

export default function StoreReviewsPage() {
  const [state, setState] = useState<StoreReviewState>('pending_review');

  const query = useQuery({
    // Same key as the sidebar badge for the pending tab — one cache entry.
    queryKey: ['admin', 'store-reviews', state],
    queryFn: ({ signal }) => listStoreReviews({ state }, { signal }),
  });

  // Resolves category slugs to their curated English names for the table
  // and the review drawer. A missing entry falls back to the raw slug.
  const categoriesQuery = useQuery({
    queryKey: ['admin', 'store-categories'],
    queryFn: ({ signal }) => listStoreCategories({ signal }),
    staleTime: 60_000,
  });

  const categoryNames = useMemo(() => {
    const map = new Map<string, string>();
    for (const category of categoriesQuery.data?.data ?? []) {
      map.set(category.slug, category.name_en);
    }
    return map;
  }, [categoriesQuery.data]);

  const counts = query.data?.meta.counts;
  const dateHeading =
    state === 'rejected'
      ? 'Rejected'
      : state === 'draft'
        ? 'Signed up'
        : 'Submitted';

  return (
    <div className="flex flex-col">
      <PageHeader
        title="Store reviews"
        description="Self-signed-up stores awaiting approval. A store is invisible everywhere public — directory, search, its own page — until it is approved here."
      />

      <Tabs
        value={state}
        onValueChange={(value) => setState(value as StoreReviewState)}
        className="mb-4"
      >
        <TabsList>
          {TABS.map((tab) => (
            <TabsTrigger key={tab.value} value={tab.value}>
              {tab.label}
              {counts ? (
                <Badge
                  variant={
                    tab.value === 'pending_review' && counts.pending_review > 0
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
                    <TableHead>Category</TableHead>
                    <TableHead>Channel</TableHead>
                    <TableHead className="text-end">Rate</TableHead>
                    <TableHead>{dateHeading}</TableHead>
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
                  ) : query.data.data.length === 0 ? (
                    <TableRow>
                      <TableCell
                        colSpan={6}
                        className="py-10 text-center text-muted-foreground"
                      >
                        {EMPTY_COPY[state]}
                      </TableCell>
                    </TableRow>
                  ) : (
                    query.data.data.map((review) => (
                      <TableRow key={review.id}>
                        <TableCell>
                          <div className="flex items-center gap-3">
                            <StoreLogo
                              name={review.name}
                              logoUrl={review.logo_url}
                            />
                            <div className="flex min-w-0 flex-col">
                              <span className="font-medium">{review.name}</span>
                              <span className="text-xs text-muted-foreground">
                                {review.slug}
                              </span>
                            </div>
                          </div>
                        </TableCell>
                        <TableCell>
                          {review.category === null ? (
                            <span className="text-muted-foreground">—</span>
                          ) : (
                            (categoryNames.get(review.category) ??
                            review.category)
                          )}
                        </TableCell>
                        <TableCell>
                          <ChannelBadge channel={review.channel} />
                        </TableCell>
                        <TableCell className="text-end">
                          {review.rate_bp === null ? (
                            <span className="text-muted-foreground">—</span>
                          ) : (
                            formatBpPercent(review.rate_bp)
                          )}
                        </TableCell>
                        <TableCell>{dateColumn(state, review)}</TableCell>
                        <TableCell className="text-end">
                          <ReviewSheet
                            review={review}
                            categoryName={
                              review.category === null
                                ? null
                                : (categoryNames.get(review.category) ?? null)
                            }
                          />
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
