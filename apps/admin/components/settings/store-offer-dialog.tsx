'use client';

import { ReactNode, useRef, useState } from 'react';
import {
  createStoreOffer,
  deleteStoreOfferImage,
  listAdminMerchants,
  OFFER_ARTWORK,
  updateStoreOffer,
  uploadStoreOfferImage,
  type StoreOffer,
} from '@manfaa/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ImagePlus, LoaderCircle } from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/lib/api-error';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';

/**
 * Create or edit one featured offer.
 *
 * The artwork is uploaded SEPARATELY from the rest, because it is a file
 * and cannot ride a JSON save — and, on a new offer, because it needs a row
 * to attach to. That is stated on screen rather than left to be discovered:
 * an offer saved without artwork is stored but not published, and the
 * listing says exactly that ("Needs artwork").
 *
 * Nothing here asks for the cashback percentage, the logo or the category.
 * Those are the store's, read live when the banner renders, so a campaign
 * cannot drift out of step with the shop it is advertising.
 */

/** "2026-08-16T14:30" (the input's own format) ↔ ISO 8601. */
function toLocalInput(iso: string | null): string {
  return iso === null ? '' : new Date(iso).toISOString().slice(0, 16);
}

function toIso(local: string): string | null {
  return local.trim() === '' ? null : new Date(local).toISOString();
}

export function StoreOfferDialog({
  offer,
  trigger,
  onSaved,
}: {
  /** Present = edit; absent = create. */
  offer?: StoreOffer;
  trigger: ReactNode;
  onSaved?: () => void;
}) {
  const [open, setOpen] = useState(false);
  const queryClient = useQueryClient();
  const editing = offer !== undefined;
  const inputRef = useRef<HTMLInputElement>(null);

  const [merchantId, setMerchantId] = useState(
    offer === undefined ? '' : String(offer.merchant_id),
  );
  const [title, setTitle] = useState(offer?.title ?? '');
  const [titleDv, setTitleDv] = useState(offer?.title_dv ?? '');
  const [blurb, setBlurb] = useState(offer?.blurb ?? '');
  const [blurbDv, setBlurbDv] = useState(offer?.blurb_dv ?? '');
  const [badge, setBadge] = useState(offer?.badge ?? '');
  const [badgeDv, setBadgeDv] = useState(offer?.badge_dv ?? '');
  const [startsAt, setStartsAt] = useState(toLocalInput(offer?.starts_at ?? null));
  const [endsAt, setEndsAt] = useState(toLocalInput(offer?.ends_at ?? null));
  const [sort, setSort] = useState(String(offer?.sort ?? 0));
  const [active, setActive] = useState(offer?.active ?? true);

  const merchants = useQuery({
    queryKey: ['admin', 'merchants'],
    queryFn: ({ signal }) => listAdminMerchants({ signal }),
    select: (response) => response.data,
    enabled: open,
  });

  const invalidate = () => {
    void queryClient.invalidateQueries({ queryKey: ['admin', 'store-offers'] });
    onSaved?.();
  };

  const save = useMutation({
    mutationFn: () => {
      const body = {
        title: title.trim(),
        title_dv: titleDv.trim() === '' ? null : titleDv.trim(),
        blurb: blurb.trim() === '' ? null : blurb.trim(),
        blurb_dv: blurbDv.trim() === '' ? null : blurbDv.trim(),
        badge: badge.trim() === '' ? null : badge.trim(),
        badge_dv: badgeDv.trim() === '' ? null : badgeDv.trim(),
        starts_at: toIso(startsAt),
        ends_at: toIso(endsAt),
        sort: sort.trim() === '' ? 0 : Number(sort),
        active,
      };

      return editing
        ? updateStoreOffer(offer.id, body)
        : createStoreOffer({ ...body, merchant_id: Number(merchantId) });
    },
    onSuccess: () => {
      invalidate();
      toast.success(editing ? 'Offer updated.' : 'Offer created.');
      setOpen(false);
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const upload = useMutation({
    mutationFn: (file: File) => uploadStoreOfferImage(offer!.id, file),
    onSuccess: () => {
      invalidate();
      toast.success('Artwork updated — this is now an image banner.');
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const remove = useMutation({
    mutationFn: () => deleteStoreOfferImage(offer!.id),
    onSuccess: () => {
      invalidate();
      toast.success('Artwork removed — this is now a text banner.');
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const canSave =
    title.trim() !== '' &&
    (editing || merchantId !== '') &&
    /^\d{1,6}$/.test(sort.trim() === '' ? '0' : sort.trim()) &&
    !save.isPending;

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>{trigger}</DialogTrigger>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>
            {editing ? 'Edit featured offer' : 'Add a featured offer'}
          </DialogTitle>
          <DialogDescription>
            A banner is one of two kinds. Attach artwork and the picture IS
            the banner, edge to edge, with nothing printed over it. Leave it
            off and the storefront lays out a designed card from the words
            below, with the store&apos;s logo and live cashback percentage
            read fresh every time it renders.
          </DialogDescription>
        </DialogHeader>
        <DialogBody className="flex flex-col gap-4">
          {!editing && (
            <div className="flex flex-col gap-2.5">
              <Label htmlFor="offer-merchant">Store</Label>
              <Select value={merchantId} onValueChange={setMerchantId}>
                <SelectTrigger id="offer-merchant">
                  <SelectValue placeholder="Choose a store" />
                </SelectTrigger>
                <SelectContent>
                  {(merchants.data ?? []).map((merchant) => (
                    <SelectItem key={merchant.id} value={String(merchant.id)}>
                      {merchant.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <p className="text-xs text-muted-foreground">
                The banner links here, and takes its rate and logo from it.
              </p>
            </div>
          )}

          {editing && (
            <div className="flex flex-col gap-2.5">
              <Label>Banner artwork</Label>
              <div className="flex items-start gap-3">
                <span className="flex aspect-video w-32 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-border bg-card">
                  {offer.image_url === null ? (
                    <ImagePlus className="size-5 text-muted-foreground" />
                  ) : (
                    <img
                      src={offer.image_url}
                      alt=""
                      className="size-full object-cover"
                    />
                  )}
                </span>
                <div className="flex flex-wrap gap-2">
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={upload.isPending || remove.isPending}
                    onClick={() => inputRef.current?.click()}
                  >
                    {upload.isPending && (
                      <LoaderCircle className="animate-spin" />
                    )}
                    {offer.image_url === null ? 'Upload artwork' : 'Replace'}
                  </Button>
                  {offer.image_url !== null && (
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      disabled={upload.isPending || remove.isPending}
                      onClick={() => remove.mutate()}
                    >
                      {remove.isPending && (
                        <LoaderCircle className="animate-spin" />
                      )}
                      Remove
                    </Button>
                  )}
                </div>
              </div>
              <input
                ref={inputRef}
                type="file"
                accept="image/png,image/jpeg,image/webp"
                className="hidden"
                onChange={(event) => {
                  const file = event.target.files?.[0];
                  event.target.value = '';
                  if (file !== undefined) upload.mutate(file);
                }}
              />
              <p className="text-xs text-muted-foreground">
                PNG, JPG or WEBP at exactly{' '}
                <strong className="font-semibold text-foreground">
                  {OFFER_ARTWORK.width}&nbsp;×&nbsp;{OFFER_ARTWORK.height}
                  &nbsp;px ({OFFER_ARTWORK.ratio})
                </strong>
                , up to {OFFER_ARTWORK.maxKb / 1024}&nbsp;MB. Another shape is
                refused rather than cropped. The card shows the artwork at
                this exact ratio, so design to the full frame — anything the
                banner needs to say has to be IN the picture, including the
                cashback percentage. Applied immediately; it does not wait
                for Save.
              </p>
            </div>
          )}

          {editing && offer.image_url !== null && (
            <p className="rounded-lg border border-border bg-muted/40 p-3 text-xs text-muted-foreground">
              This is an <strong className="font-semibold">image banner</strong>
              , so the words below are not printed on the storefront. They
              still name the offer in this list and are read aloud by screen
              readers, so keep the headline accurate.
            </p>
          )}

          <div className="flex flex-col gap-2.5">
            <Label htmlFor="offer-title">Headline</Label>
            <Input
              id="offer-title"
              value={title}
              maxLength={120}
              placeholder="e.g. Ramadan at Island Mart"
              onChange={(event) => setTitle(event.target.value)}
            />
          </div>

          <div className="flex flex-col gap-2.5">
            <Label htmlFor="offer-title-dv">Headline (Dhivehi)</Label>
            <Input
              id="offer-title-dv"
              dir="rtl"
              lang="dv"
              value={titleDv}
              maxLength={120}
              onChange={(event) => setTitleDv(event.target.value)}
            />
          </div>

          <div className="flex flex-col gap-2.5">
            <Label htmlFor="offer-blurb">Blurb</Label>
            <Textarea
              id="offer-blurb"
              rows={2}
              value={blurb}
              maxLength={240}
              placeholder="One line under the headline."
              onChange={(event) => setBlurb(event.target.value)}
            />
          </div>

          <div className="flex flex-col gap-2.5">
            <Label htmlFor="offer-blurb-dv">Blurb (Dhivehi)</Label>
            <Textarea
              id="offer-blurb-dv"
              dir="rtl"
              lang="dv"
              rows={2}
              value={blurbDv}
              maxLength={240}
              onChange={(event) => setBlurbDv(event.target.value)}
            />
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <div className="flex flex-col gap-2.5">
              <Label htmlFor="offer-badge">Badge</Label>
              <Input
                id="offer-badge"
                value={badge}
                maxLength={40}
                placeholder="Limited time"
                onChange={(event) => setBadge(event.target.value)}
              />
            </div>
            <div className="flex flex-col gap-2.5">
              <Label htmlFor="offer-badge-dv">Badge (Dhivehi)</Label>
              <Input
                id="offer-badge-dv"
                dir="rtl"
                lang="dv"
                value={badgeDv}
                maxLength={40}
                onChange={(event) => setBadgeDv(event.target.value)}
              />
            </div>
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <div className="flex flex-col gap-2.5">
              <Label htmlFor="offer-starts">Starts</Label>
              <Input
                id="offer-starts"
                type="datetime-local"
                value={startsAt}
                onChange={(event) => setStartsAt(event.target.value)}
              />
            </div>
            <div className="flex flex-col gap-2.5">
              <Label htmlFor="offer-ends">Ends</Label>
              <Input
                id="offer-ends"
                type="datetime-local"
                value={endsAt}
                onChange={(event) => setEndsAt(event.target.value)}
              />
            </div>
          </div>
          <p className="text-xs text-muted-foreground">
            Leave both empty to run until you switch it off. An end date
            retires the banner on its own.
          </p>

          <div className="grid gap-4 sm:grid-cols-2">
            <div className="flex flex-col gap-2.5">
              <Label htmlFor="offer-sort">Sort order</Label>
              <Input
                id="offer-sort"
                inputMode="numeric"
                value={sort}
                onChange={(event) => setSort(event.target.value)}
              />
              <p className="text-xs text-muted-foreground">
                Lower numbers show first.
              </p>
            </div>
            <div className="flex flex-col gap-2.5">
              <Label htmlFor="offer-active">Active</Label>
              <div className="flex h-9 items-center">
                <Switch
                  id="offer-active"
                  checked={active}
                  onCheckedChange={setActive}
                />
              </div>
            </div>
          </div>
        </DialogBody>
        <DialogFooter>
          <Button variant="outline" onClick={() => setOpen(false)}>
            Cancel
          </Button>
          <Button disabled={!canSave} onClick={() => save.mutate()}>
            {save.isPending && <LoaderCircle className="animate-spin" />}
            {editing ? 'Save changes' : 'Create offer'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
