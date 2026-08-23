'use client';

import { useEffect, useState } from 'react';
import { getReferrals, type ReferralFriend } from '@manfaa/api-client';
import { useFormatMoney } from '@manfaa/ui';
import { useQuery } from '@tanstack/react-query';
import { Check, Copy, Gift, Link2, Share2, TriangleAlert } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';
import { useCopyToClipboard } from '@/hooks/use-copy-to-clipboard';
import { Alert, AlertIcon, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import {
  Toolbar,
  ToolbarDescription,
  ToolbarHeading,
  ToolbarPageTitle,
} from '@/components/app-layout/toolbar';
import { ErrorBlock } from '@/components/app/async-states';

/**
 * The referral programme (owner spec, 2026-08-23). The customer's existing
 * 6-digit Manfaa code IS the referral code — nothing new to memorise. A
 * friend enters it at signup; when their validated spend reaches the
 * programme threshold, the reward lands in THIS customer's wallet.
 *
 * Amounts come from the API config (never hardcoded) and render as MVR —
 * integer laari stays in the data layer, per the money rules.
 */

/** The code itself — spaced digits pinned LTR, same voice as the dashboard. */
function CodeDigits({ code, className }: { code: string; className?: string }) {
  return (
    <span
      className={cn(
        '-me-[0.2em] font-semibold tracking-[0.2em] text-mono tabular-nums',
        className,
      )}
      dir="ltr"
    >
      {code}
    </span>
  );
}

function FriendRow({
  friend,
  thresholdLaari,
}: {
  friend: ReferralFriend;
  thresholdLaari: number;
}) {
  const { t } = useTranslation();
  const formatMoney = useFormatMoney();

  const percent =
    thresholdLaari > 0
      ? Math.min(100, Math.round((friend.spent_laari / thresholdLaari) * 100))
      : 0;

  return (
    <div className="flex flex-col gap-2 py-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex min-w-0 flex-col">
          {/* Masked by the API (e.g. "Ah***ed") — never the full name. */}
          <span className="truncate text-sm font-medium text-mono" dir="ltr">
            {friend.name}
          </span>
          {friend.joined_at !== null && (
            <span className="text-xs text-muted-foreground">
              {t('referrals.joined', { date: formatDate(friend.joined_at) })}
            </span>
          )}
        </div>

        {friend.rewarded ? (
          // Amount-free, like mobile: the CURRENT reward setting may differ
          // from what this friend actually paid out, and the API carries no
          // per-friend figure — an amount here could misstate history.
          <Badge variant="success" appearance="light" size="sm">
            <Gift className="size-3" />
            {t('referrals.rewardedBadge')}
          </Badge>
        ) : (
          <span className="text-xs text-muted-foreground">
            {t('referrals.progress', {
              spent: formatMoney(friend.spent_laari),
              threshold: formatMoney(thresholdLaari),
            })}
          </span>
        )}
      </div>

      {/* Width-driven (like the dashboard's payout bar) so it fills from
          the inline start and mirrors under Dhivehi for free. */}
      {!friend.rewarded && (
        <div
          role="progressbar"
          aria-valuemin={0}
          aria-valuemax={100}
          aria-valuenow={percent}
          aria-label={t('referrals.friendsTitle')}
          className="h-1.5 w-full overflow-hidden rounded-full bg-brand/15"
        >
          <div
            className="h-full rounded-full bg-brand transition-[width] duration-500"
            style={{ width: `${percent}%` }}
          />
        </div>
      )}
    </div>
  );
}

export default function ReferralsPage() {
  const { t } = useTranslation();
  const formatMoney = useFormatMoney();
  const { isCopied: codeCopied, copyToClipboard: copyCode } =
    useCopyToClipboard();
  const { isCopied: linkCopied, copyToClipboard: copyLink } =
    useCopyToClipboard();

  // navigator.share exists only in the browser (and not in every one) —
  // resolved after mount so the server and client render the same tree.
  const [canShare, setCanShare] = useState(false);
  useEffect(() => {
    setCanShare(typeof navigator.share === 'function');
  }, []);

  const referrals = useQuery({
    queryKey: ['referrals'],
    queryFn: ({ signal }) => getReferrals({ signal }),
    retry: false,
  });

  if (referrals.isPending) return <Skeleton className="h-96 w-full" />;
  if (referrals.error) return <ErrorBlock error={referrals.error} />;

  const data = referrals.data?.data;
  if (!data) return null;

  const reward = formatMoney(data.reward_laari);
  const threshold = formatMoney(data.threshold_laari);

  const share = () => {
    navigator
      .share({
        text: t('referrals.shareText', { code: data.code, url: data.share_url }),
        url: data.share_url,
      })
      .catch(() => {
        // Dismissed the share sheet — nothing to do.
      });
  };

  return (
    <div className="flex flex-col gap-5">
      <Toolbar>
        <ToolbarHeading>
          <ToolbarPageTitle>{t('referrals.title')}</ToolbarPageTitle>
          <ToolbarDescription>{t('referrals.subtitle')}</ToolbarDescription>
        </ToolbarHeading>
      </Toolbar>

      {!data.enabled && (
        <Alert variant="warning" appearance="light">
          <AlertIcon>
            <TriangleAlert />
          </AlertIcon>
          <AlertTitle>{t('referrals.paused')}</AlertTitle>
        </Alert>
      )}

      {/* Hero: the code, big — and every way to hand it to a friend. */}
      <Card>
        <CardContent className="flex flex-col items-center gap-4 p-6 text-center">
          <span className="self-start text-start text-2xs font-semibold uppercase tracking-wider text-muted-foreground">
            {t('referrals.yourCode')}
          </span>

          <CodeDigits code={data.code} className="text-4xl" />

          {/* Promise the bonus only while the programme actually pays it —
              the paused Alert above owns the story otherwise (mirrors the
              mobile screen's guard). */}
          {data.enabled && (
            <span className="max-w-sm text-xs text-muted-foreground">
              {t('referrals.explainer', { reward, threshold })}
            </span>
          )}

          <div className="flex flex-wrap items-center justify-center gap-2">
            <Button
              size="sm"
              variant="outline"
              onClick={() => copyCode(data.code)}
            >
              {codeCopied ? <Check className="text-brand" /> : <Copy />}
              {codeCopied ? t('referrals.codeCopied') : t('referrals.copyCode')}
            </Button>
            <Button
              size="sm"
              variant="outline"
              onClick={() => copyLink(data.share_url)}
            >
              {linkCopied ? <Check className="text-brand" /> : <Link2 />}
              {linkCopied ? t('referrals.linkCopied') : t('referrals.copyLink')}
            </Button>
            {canShare && (
              <Button size="sm" onClick={share}>
                <Share2 />
                {t('referrals.share')}
              </Button>
            )}
          </div>
        </CardContent>
      </Card>

      {/* The programme at a glance: invited / rewarded / total earned. */}
      <div className="grid grid-cols-3 gap-3">
        {(
          [
            ['referrals.statInvited', String(data.stats.invited)],
            ['referrals.statRewarded', String(data.stats.rewarded)],
            ['referrals.statEarned', formatMoney(data.stats.earned_total_laari)],
          ] as const
        ).map(([labelKey, value]) => (
          <Card key={labelKey}>
            <CardContent className="flex flex-col gap-1 p-4">
              <span className="text-2xs font-semibold uppercase tracking-wider text-muted-foreground">
                {t(labelKey)}
              </span>
              <span className="truncate text-lg font-semibold text-mono">
                {value}
              </span>
            </CardContent>
          </Card>
        ))}
      </div>

      <Card>
        <CardHeader>
          <CardTitle>{t('referrals.friendsTitle')}</CardTitle>
        </CardHeader>
        <CardContent>
          {data.friends.length === 0 ? (
            <div className="flex flex-col items-center gap-2 py-8 text-center text-sm text-muted-foreground">
              <Gift className="size-6 text-muted-foreground/60" />
              <span className="font-medium text-mono">
                {t('referrals.emptyTitle')}
              </span>
              {data.enabled && (
                <span className="max-w-sm">
                  {t('referrals.emptyBody', { reward, threshold })}
                </span>
              )}
            </div>
          ) : (
            <div className="divide-y divide-border">
              {/* Index keys, deliberately: names are MASKED and joined_at
                  is second-precision, so neither is unique — and the list
                  is never reordered client-side. */}
              {data.friends.map((friend, index) => (
                <FriendRow
                  key={index}
                  friend={friend}
                  thresholdLaari={data.threshold_laari}
                />
              ))}
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
