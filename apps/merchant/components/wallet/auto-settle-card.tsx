'use client';

import { Zap } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { apiErrorMessage, useSetAutoSettleFromWallet } from '@/lib/queries';
import { Card, CardContent } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';

/**
 * The auto-settle toggle (owner, 2026-08-24): whether the hourly run may
 * settle validated cashback from the wallet balance, oldest first, as much
 * as fits. Read off the wallet payload; written through the preferences
 * route, which is `preferences.update` — the owner's — so staff see the
 * setting but cannot move it, and are told why rather than shown a switch
 * that silently refuses.
 */
export function AutoSettleCard({
  enabled,
  canToggle,
}: {
  /** `auto_settle_from_wallet` off the wallet read; undefined while loading. */
  enabled: boolean | undefined;
  canToggle: boolean;
}) {
  const { t } = useTranslation();
  const setAutoSettle = useSetAutoSettleFromWallet();

  const toggle = (next: boolean) => {
    setAutoSettle.mutate(next, {
      onSuccess: () =>
        toast.success(t(next ? 'wallet.autoSettle.on' : 'wallet.autoSettle.off')),
      onError: (error) =>
        toast.error(apiErrorMessage(error, t('wallet.autoSettle.failed'))),
    });
  };

  return (
    <Card>
      <CardContent className="flex items-start gap-4 p-5">
        <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary/10">
          <Zap className="size-5 text-primary" />
        </span>
        <div className="flex min-w-0 grow flex-col gap-1">
          <span className="text-sm font-medium" id="auto-settle-label">
            {t('wallet.autoSettle.title')}
          </span>
          <span className="text-sm text-secondary-foreground">
            {t('wallet.autoSettle.body')}
          </span>
          {!canToggle && (
            <span className="text-xs text-muted-foreground">
              {t('wallet.autoSettle.ownerOnly')}
            </span>
          )}
        </div>
        {enabled === undefined ? (
          <Skeleton className="h-6 w-10 shrink-0 rounded-full" />
        ) : (
          <Switch
            aria-labelledby="auto-settle-label"
            checked={enabled}
            disabled={!canToggle || setAutoSettle.isPending}
            onCheckedChange={toggle}
            className="shrink-0"
          />
        )}
      </CardContent>
    </Card>
  );
}
