'use client';

import { useState } from 'react';
import {
  listStoreOffers,
  OFFER_ARTWORK,
  type OfferLiveState,
  type StoreOffer,
} from '@manfaa/api-client';
import { useQuery } from '@tanstack/react-query';
import { Pencil, Plus, Type } from 'lucide-react';
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
 * Featured offers — the banners at the top of the customer's Discover
 * screen, and later the app's.
 *
 * Two kinds, and the Kind column says which. An IMAGE banner is the
 * uploaded artwork edge to edge, at one fixed ratio, with nothing printed
 * over it. A TEXT banner has no artwork: the storefront lays it out from
 * the words here, and its logo and cashback percentage are read live from
 * the store, so that kind can never advertise a rate the shop has moved off.
 *
 * The Status column is the other point of this screen. "Saved but
 * invisible" is a normal state — switched off, or scheduled for next week —
 * and an admin should never have to guess which.
 */

const LIVE_LABEL: Record<OfferLiveState, string> = {
  live: 'On the storefront',
  inactive: 'Switched off',
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
        <span className="flex aspect-video w-20 items-center justify-center overflow-hidden rounded-md border border-border bg-card">
          {offer.image_url === null ? (
            <Type className="size-4 text-muted-foreground" />
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
        <Badge variant="secondary" appearance="light" size="sm">
          {offer.kind === 'image' ? 'Image' : 'Text'}
        </Badge>
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
            The banners at the top of Discover. An offer with artwork IS the
            artwork, edge to edge at {OFFER_ARTWORK.width}&nbsp;×&nbsp;
            {OFFER_ARTWORK.height}&nbsp;px; one without is a designed text
            card whose logo and cashback percentage are read live from the
            store.
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
                  <TableHead className="w-24">Banner</TableHead>
                  <TableHead>Offer</TableHead>
                  <TableHead>Kind</TableHead>
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
                      <TableCell colSpan={9}>
                        <Skeleton className="h-8 w-full" />
                      </TableCell>
                    </TableRow>
                  ))
                ) : offers.length === 0 ? (
                  <TableRow>
                    <TableCell
                      colSpan={9}
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
