'use client';

import { useRef, useState } from 'react';
import {
  listBrandAssets,
  resetBrandAsset,
  uploadBrandAsset,
  type BrandAsset,
} from '@manfaa/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ImageUp, LoaderCircle, RotateCcw, TriangleAlert } from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/lib/api-error';
import {
  Alert,
  AlertContent,
  AlertDescription,
  AlertIcon,
  AlertTitle,
} from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';

/**
 * The platform's five brand marks.
 *
 * These replace what used to be committed files duplicated across apps/web
 * and apps/merchant — changeable only by a deploy. Every website surface now
 * draws from these five, so a new logo is one upload rather than a hunt
 * through three codebases.
 *
 * The preview is the live URL, not a local object URL: what is shown here is
 * literally what a visitor gets. The `?t=` on it is only to defeat the
 * browser's own memory of the <img> after a replace — the server itself
 * revalidates by ETag and needs no token.
 */
export function BrandAssets({ canEdit }: { canEdit: boolean }) {
  const queryClient = useQueryClient();
  const [stamp, setStamp] = useState(() => Date.now());

  const query = useQuery({
    queryKey: ['admin', 'brand-assets'],
    queryFn: ({ signal }) => listBrandAssets({ signal }),
    enabled: canEdit,
  });

  const refresh = (data: { data: BrandAsset[] }) => {
    queryClient.setQueryData(['admin', 'brand-assets'], data);
    setStamp(Date.now());
  };

  if (!canEdit) {
    return null;
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Logos and favicon</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-5">
        <p className="text-sm text-muted-foreground">
          Five files cover every website surface. The landscape mark is used in
          page headers; the square one where a header would be too wide — the
          customer sign-in card. Light and dark are picked automatically by the
          visitor&apos;s theme. Nothing needs a deploy: a replacement is live on
          the next page load.
        </p>

        <Alert appearance="light">
          <AlertIcon>
            <TriangleAlert />
          </AlertIcon>
          <AlertContent>
            <AlertTitle>PNG, JPEG or WebP — not SVG</AlertTitle>
            <AlertDescription>
              An SVG can carry script, and these are served from our own domains
              on every page including sign-in, so one hostile file would run
              everywhere. Export a PNG at twice the size you need.
            </AlertDescription>
          </AlertContent>
        </Alert>

        {query.isPending ? (
          <div className="flex flex-col gap-3">
            {Array.from({ length: 5 }).map((_, i) => (
              <Skeleton key={i} className="h-20 w-full" />
            ))}
          </div>
        ) : query.isError ? (
          <Alert variant="destructive" appearance="light">
            <AlertIcon>
              <TriangleAlert />
            </AlertIcon>
            <AlertDescription>{apiErrorMessage(query.error)}</AlertDescription>
          </Alert>
        ) : (
          <div className="flex flex-col gap-3">
            {(query.data?.data ?? []).map((asset) => (
              <BrandRow
                key={asset.slot}
                asset={asset}
                stamp={stamp}
                onChanged={refresh}
              />
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  );
}

function BrandRow({
  asset,
  stamp,
  onChanged,
}: {
  asset: BrandAsset;
  stamp: number;
  onChanged: (data: { data: BrandAsset[] }) => void;
}) {
  const input = useRef<HTMLInputElement>(null);

  const upload = useMutation({
    mutationFn: (file: File) => uploadBrandAsset(asset.slot, file),
    onSuccess: (data) => {
      onChanged(data);
      toast.success(`${asset.label} updated.`);
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const reset = useMutation({
    mutationFn: () => resetBrandAsset(asset.slot),
    onSuccess: (data) => {
      onChanged(data);
      toast.success(`${asset.label} reset to the built-in mark.`);
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const busy = upload.isPending || reset.isPending;

  return (
    <div className="flex flex-wrap items-center gap-4 rounded-md border border-border p-4">
      {/* Checkerboard, so a transparent mark is visible as transparent —
          and the dark variants sit on dark, which is where they belong. */}
      <div
        className={`flex h-16 shrink-0 items-center justify-center rounded border border-border p-2 ${
          asset.slot.endsWith('_dark') ? 'bg-zinc-900' : 'bg-white'
        } ${asset.shape === 'landscape' ? 'w-44' : 'w-16'}`}
      >
        {/* eslint-disable-next-line @next/next/no-img-element -- the live URL, deliberately */}
        <img
          src={`${asset.url}?t=${stamp}`}
          alt={asset.label}
          className="max-h-full max-w-full object-contain"
        />
      </div>

      <div className="flex min-w-0 flex-1 flex-col gap-1">
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-sm font-medium">{asset.label}</span>
          {asset.is_custom ? (
            <Badge variant="primary" appearance="light" size="sm">
              Custom
            </Badge>
          ) : (
            <Badge variant="secondary" appearance="light" size="sm">
              Built-in
            </Badge>
          )}
        </div>
        <span className="text-xs text-muted-foreground">
          {asset.is_custom
            ? `${asset.original_name ?? 'Uploaded'}${
                asset.updated_by ? ` — ${asset.updated_by}` : ''
              }`
            : 'No upload; the built-in Manfaa mark is showing.'}
        </span>
      </div>

      <div className="flex items-center gap-2">
        <input
          ref={input}
          type="file"
          accept="image/png,image/jpeg,image/webp,image/x-icon,.ico"
          className="hidden"
          onChange={(event) => {
            const file = event.target.files?.[0];
            if (file) upload.mutate(file);
            // Clear it, so re-picking the same file fires again.
            event.target.value = '';
          }}
        />
        <Button
          variant="outline"
          size="sm"
          disabled={busy}
          onClick={() => input.current?.click()}
        >
          {upload.isPending ? (
            <LoaderCircle className="animate-spin" />
          ) : (
            <ImageUp />
          )}
          Replace
        </Button>
        {asset.is_custom && (
          <Button
            variant="ghost"
            size="sm"
            disabled={busy}
            onClick={() => reset.mutate()}
          >
            <RotateCcw />
            Reset
          </Button>
        )}
      </div>
    </div>
  );
}
