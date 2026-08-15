'use client';

import { useState } from 'react';
import {
  listStoreOffers,
  type OfferLiveState,
  type StoreOffer,
} from '@manfaa/api-client';
import { useQuery } from '@tanstack/react-query';
import { ImageOff, Pencil, Plus } from 'lucide-react';
import { format } from 'date-fns';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import { StoreOfferDialog } from '@/components/settings/store-offer-dialog';

/**
 * Featured offers — the image banners at the top of the customer's Discover
 * screen, and later the app's.
 *
 * An offer carries artwork, words and a schedule. It carries no merchant
 * FACTS: the cashback percentage, logo and category on the rendered banner
 * are read live from the store, so a banner can never advertise a rate the
 * store has moved off or a shop that has been suspended.
 *
 * The `live` column is the point of this screen. "Saved but invisible" is
 * the normal state of a new offer — no artwork yet, or scheduled for next
 * week — and an admin should never have to guess which.
 */

const LIVE_LABEL: Record<OfferLiveState, string> = {
  live: 'On the storefront',
  inactive: 'Switched off',
  no_image: 'Needs artwork',
  scheduled: 'Scheduled',
  ended: 'Ended',
  store_not_trading: 'Store not trading',
};

const LIVE_VARIANT: Record<
  OfferLiveState,
  'success' | 'secondary' | 'warning' | 'destructive'
> = {
  live: 'success',
  inactive: 'secondary',
  no_image: 'warning',
  scheduled: 'warning',
  ended: 'secondary',
  store_not_trading: 'destructive',
};

function OfferRow({ offer }: { offer: StoreOffer }) {
  return (
    <TableRow>
      <TableCell className="text-end text-muted-foreground">
        {offer.sort}
      </TableCell>
      <TableCell>
        <span className="flex h-10 w-16 items-center justify-center overflow-hidden rounded-md border border-border bg-card">
          {offer.image_url === null ? (
            <ImageOff className="size-4 text-muted-foreground" />
          ) : (
            <img
              src={offer.image_url}
              alt=""
              className="size-full object-cover"
            />
          )}
        </span>
      </TableCell>
      <TableCell>
        <div className="flex flex-col">
          <span className="font-medium">{offer.title}</span>
          {offer.blurb !== null && (
            <span className="line-clamp-1 text-xs text-muted-foreground">
              {offer.blurb}
            </span>
          )}
        </div>
      </TableCell>
      <TableCell>
        <span className="text-sm">{offer.merchant?.name ?? '—'}</span>
      </TableCell>
      <TableCell>
        {offer.badge === null ? (
          <span className="text-muted-foreground">—</span>
        ) : (
          <Badge variant="secondary" appearance="light" size="sm">
            {offer.badge}
          </Badge>
        )}
      </TableCell>
      <TableCell className="whitespace-nowrap text-sm text-muted-foreground">
        {offer.starts_at === null && offer.ends_at === null
          ? 'Always'
          : `${offer.starts_at === null ? '—' : format(new Date(offer.starts_at), 'd MMM yyyy')} → ${
              offer.ends_at === null
                ? '—'
                : format(new Date(offer.ends_at), 'd MMM yyyy')
            }`}
      </TableCell>
      <TableCell>
        <Badge variant={LIVE_VARIANT[offer.live]} appearance="light" size="sm">
          {LIVE_LABEL[offer.live]}
        </Badge>
      </TableCell>
      <TableCell className="text-end">
        <StoreOfferDialog
          offer={offer}
          trigger={
            <Button variant="outline" size="sm">
              <Pencil />
              Edit
            </Button>
          }
        />
      </TableCell>
    </TableRow>
  );
}

export default function OffersPage() {
  const [, setRefreshed] = useState(0);
  const query = useQuery({
    queryKey: ['admin', 'store-offers'],
    queryFn: ({ signal }) => listStoreOffers({ signal }),
    select: (response) => response.data,
  });

  const offers = query.data ?? [];

  return (
    <div className="flex flex-col gap-5">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex flex-col gap-1">
          <h1 className="text-xl font-semibold text-mono">Featured offers</h1>
          <p className="text-sm text-muted-foreground">
            The image banners at the top of Discover. The cashback percentage,
            logo and category shown on each banner are read live from the
            store — only the artwork, words and schedule live here.
          </p>
        </div>
        <StoreOfferDialog
          onSaved={() => setRefreshed((count) => count + 1)}
          trigger={
            <Button>
              <Plus />
              Add offer
            </Button>
          }
        />
      </div>

      <Card>
        <CardTable>
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-16 text-end">Sort</TableHead>
                  <TableHead className="w-20">Banner</TableHead>
                  <TableHead>Offer</TableHead>
                  <TableHead>Store</TableHead>
                  <TableHead>Badge</TableHead>
                  <TableHead>Runs</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="text-end">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {query.isPending ? (
                  Array.from({ length: 3 }).map((_, index) => (
                    <TableRow key={index}>
                      <TableCell colSpan={8}>
                        <Skeleton className="h-8 w-full" />
                      </TableCell>
                    </TableRow>
                  ))
                ) : offers.length === 0 ? (
                  <TableRow>
                    <TableCell
                      colSpan={8}
                      className="py-10 text-center text-muted-foreground"
                    >
                      No offers yet. Add one to put a store on the front of
                      Discover.
                    </TableCell>
                  </TableRow>
                ) : (
                  offers.map((offer) => (
                    <OfferRow key={offer.id} offer={offer} />
                  ))
                )}
              </TableBody>
            </Table>
          </div>
        </CardTable>
      </Card>
    </div>
  );
}
