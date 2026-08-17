'use client';

import { useRef, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import type { Customer } from '@manfaa/api-client';
import { removeCustomerAvatar, uploadCustomerAvatar } from '@manfaa/api-client';
import { Camera, Loader2, Trash2 } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { ApiError } from '@manfaa/api-client';
import { queryKeys } from '@/lib/queries';

/**
 * The customer's profile picture with its editor: the circle shows the
 * photo (or the initial), a camera badge opens the file picker, and a
 * remove action appears only once a photo exists. On success the cached
 * `me` is updated in place so the header repaints without a refetch —
 * the avatar URL is content-addressed server-side (new uuid per upload),
 * so there is no staleness to chase.
 */
export function AvatarEditor({ me }: { me: Customer }) {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const inputRef = useRef<HTMLInputElement>(null);
  const [busy, setBusy] = useState(false);

  const initial = me.name.trim().charAt(0).toUpperCase() || '?';

  const applyUrl = (avatarUrl: string | null) => {
    queryClient.setQueryData<Customer>(queryKeys.me, (previous) =>
      previous ? { ...previous, avatar_url: avatarUrl } : previous,
    );
  };

  const onPick = async (file: File | undefined) => {
    if (!file || busy) return;
    setBusy(true);
    try {
      const { data } = await uploadCustomerAvatar(file);
      applyUrl(data.avatar_url);
      toast.success(t('dashboard.avatarUpdated'));
    } catch (error) {
      toast.error(
        error instanceof ApiError && error.message
          ? error.message
          : t('dashboard.avatarFailed'),
      );
    } finally {
      setBusy(false);
      // Same file re-picked later must fire onChange again.
      if (inputRef.current) inputRef.current.value = '';
    }
  };

  const onRemove = async () => {
    if (busy) return;
    setBusy(true);
    try {
      await removeCustomerAvatar();
      applyUrl(null);
      toast.success(t('dashboard.avatarRemoved'));
    } catch (error) {
      toast.error(
        error instanceof ApiError && error.message
          ? error.message
          : t('dashboard.avatarFailed'),
      );
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="flex items-center gap-3">
      <div className="relative shrink-0">
        <button
          type="button"
          onClick={() => inputRef.current?.click()}
          disabled={busy}
          aria-label={t('dashboard.avatarChange')}
          className="size-16 rounded-full border border-border bg-muted inline-flex items-center justify-center overflow-hidden text-xl font-semibold text-secondary-foreground cursor-pointer hover:opacity-90 disabled:cursor-wait"
        >
          {me.avatar_url ? (
            // eslint-disable-next-line @next/next/no-img-element -- capability URL on the API origin; next/image adds nothing here
            <img
              src={me.avatar_url}
              alt=""
              className="size-full object-cover"
            />
          ) : (
            initial
          )}
        </button>
        <span className="absolute -bottom-0.5 -end-0.5 size-6 rounded-full bg-primary text-primary-foreground border-2 border-background inline-flex items-center justify-center pointer-events-none">
          {busy ? (
            <Loader2 className="size-3.5 animate-spin" />
          ) : (
            <Camera className="size-3.5" />
          )}
        </span>
      </div>
      {me.avatar_url && !busy && (
        <button
          type="button"
          onClick={onRemove}
          className="text-xs text-muted-foreground hover:text-destructive inline-flex items-center gap-1 cursor-pointer"
        >
          <Trash2 className="size-3.5" />
          {t('dashboard.avatarRemove')}
        </button>
      )}
      <input
        ref={inputRef}
        type="file"
        accept="image/jpeg,image/png,image/webp"
        className="hidden"
        onChange={(event) => void onPick(event.target.files?.[0])}
      />
    </div>
  );
}
