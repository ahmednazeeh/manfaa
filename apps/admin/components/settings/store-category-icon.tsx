'use client';

import { useRef, useState } from 'react';
import {
  deleteStoreCategoryIcon,
  uploadStoreCategoryIcon,
  type StoreCategory,
} from '@manfaa/api-client';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { ImagePlus, LoaderCircle, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/lib/api-error';
import { Button } from '@/components/ui/button';

/**
 * The artwork a category shows on the public rail.
 *
 * Two facts drive the design here. First, the upload is a FILE, so it does
 * not ride the category's JSON save — it is its own request, applied the
 * moment a file is chosen, and the "Save changes" button below it has
 * nothing to do with it. That is stated on screen rather than left for an
 * admin to discover by cancelling and finding their icon already changed.
 *
 * Second, a category is never without an icon: clearing the artwork falls
 * back to the curated glyph the storefront draws, so "Remove" is safe and
 * is worded as a return to the default rather than a deletion.
 */
export function StoreCategoryIconField({
  category,
}: {
  category: StoreCategory;
}) {
  const queryClient = useQueryClient();
  const inputRef = useRef<HTMLInputElement>(null);
  const [preview, setPreview] = useState<string | null>(null);

  const invalidate = () =>
    queryClient.invalidateQueries({ queryKey: ['admin', 'store-categories'] });

  const upload = useMutation({
    mutationFn: (file: File) => uploadStoreCategoryIcon(category.id, file),
    onSuccess: () => {
      void invalidate();
      toast.success('Icon updated.');
    },
    onError: (error) => {
      setPreview(null);
      toast.error(apiErrorMessage(error));
    },
  });

  const clear = useMutation({
    mutationFn: () => deleteStoreCategoryIcon(category.id),
    onSuccess: () => {
      setPreview(null);
      void invalidate();
      toast.success('Icon removed — the category shows its default glyph.');
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const busy = upload.isPending || clear.isPending;
  const shown = preview ?? category.icon_url;

  return (
    <div className="flex flex-col gap-2.5">
      <span className="text-sm font-medium">Icon</span>

      <div className="flex items-center gap-3">
        <span className="flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-2xl border border-border bg-card">
          {shown === null ? (
            <ImagePlus className="size-5 text-muted-foreground" />
          ) : (
            <img src={shown} alt="" className="size-full object-cover" />
          )}
        </span>

        <div className="flex flex-wrap items-center gap-2">
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={busy}
            onClick={() => inputRef.current?.click()}
          >
            {upload.isPending && <LoaderCircle className="animate-spin" />}
            {category.icon_url === null ? 'Upload icon' : 'Replace'}
          </Button>

          {category.icon_url !== null && (
            <Button
              type="button"
              variant="ghost"
              size="sm"
              disabled={busy}
              onClick={() => clear.mutate()}
            >
              {clear.isPending ? (
                <LoaderCircle className="animate-spin" />
              ) : (
                <Trash2 />
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
          // Reset the input so re-picking the same file fires onChange again.
          event.target.value = '';
          if (file === undefined) return;

          // Optimistic preview: the tile shows the chosen file while the
          // request is in flight, and reverts if the server refuses it.
          setPreview(URL.createObjectURL(file));
          upload.mutate(file);
        }}
      />

      <p className="text-xs text-muted-foreground">
        PNG, JPG or WEBP, at least 64×64 and up to 512&nbsp;KB. Square artwork
        fits the storefront tile best. Applied immediately — it does not wait
        for Save. With no icon the category shows a default glyph.
      </p>
    </div>
  );
}
