'use client';

import {
  getAdminPlatformSettings,
  PlatformSettingKeySchema,
  type PlatformSetting,
  type PlatformSettingKey,
} from '@manfaa/api-client';
import { useQuery } from '@tanstack/react-query';
import { TriangleAlert } from 'lucide-react';
import { apiErrorMessage } from '@/lib/api-error';
import { Alert, AlertDescription, AlertIcon } from '@/components/ui/alert';
import { Card, CardContent } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';
import { PageHeader } from '@/components/admin/page-header';
import {
  PlatformSettingRow,
  type SettingMeta,
} from '@/components/settings/platform-setting-row';

/**
 * Plain-language meaning of each key. The API sends each value in the unit
 * its name declares — integer laari, whole days, or a 2-decimal percent
 * string for a rate — and the row component converts to and from the
 * display text without ever passing money or a rate through a float.
 */
const SETTING_META: Record<PlatformSettingKey, SettingMeta> = {
  min_payout_laari: {
    label: 'Minimum payout',
    description:
      'Confirmed balances below this carry to the next batch instead of being paid out. Closing an account always pays the full balance regardless.',
    unit: 'mvr',
  },
  settlement_due_days: {
    label: 'Settlement window',
    description:
      'Days a merchant has to fund a reward once its validation window closes. The day after this lapses, suspension is automatic — it is the only credit control.',
    unit: 'days',
  },
  write_off_days: {
    label: 'Write-off horizon',
    description:
      'Rewards still unsettled this many days past due are written off and the customer is told the merchant never settled. Nothing sits pending indefinitely.',
    unit: 'days',
  },
  default_validation_window_days: {
    label: 'Maximum validation window',
    description:
      'The longest refund window a store may set for itself (Merchant panel › Settings › Preferences). Stores start on the new-merchant window below and may raise it up to this ceiling. The settlement clock starts only when a sale\u2019s window closes.',
    unit: 'days',
  },
  default_min_eligible_laari: {
    label: 'Default minimum eligible sale',
    description:
      'Sales below this earn no cashback — they are still recorded, with a below-minimum reason, so the till sees something truthful. Applied to new merchants.',
    unit: 'mvr',
  },
  prompt_discount_rate_percent: {
    label: 'Prompt-payment discount',
    description:
      'Taken off the PLATFORM FEE — never off the customer’s cashback — when a merchant settles every outstanding transaction and each one is still inside the age window below. Set to 0% to switch the incentive off entirely.',
    unit: 'percent',
  },
  new_merchant_validation_window_days: {
    label: 'New merchant validation window',
    description:
      'The refund window a newly approved store starts on (never above the maximum above). Each store can change its own afterwards; changing this never touches a store that already exists.',
    unit: 'days',
  },
  marketplace_enabled: {
    label: 'Marketplace',
    description:
      'The whole marketplace, on or off. With it off the Market tab, the customer storefront, order tracking and every merchant marketplace menu disappear — and the routes refuse as well, so an old app build cannot walk past it. Cashback is unaffected.',
    unit: 'toggle',
  },
  marketplace_fee_percent: {
    label: 'Marketplace fee',
    description:
      'The platform’s cut of a marketplace order, charged on items and never on delivery. Frozen onto each order when it is placed, so changing this never restates one already made. A store may be given its own rate, which overrides this.',
    unit: 'percent',
  },
  prompt_discount_max_age_days: {
    label: 'Prompt-payment age window',
    description:
      'How young every transaction in the batch must be, counted from the day its settlement clock started, for the discount to be granted. Keep it shorter than the settlement window — at or past it, every merchant would qualify and the incentive would reward nothing.',
    unit: 'days',
  },
  referral_enabled: {
    label: 'Referral programme',
    description:
      'The referral programme, on or off. Off pauses bonus payouts — nothing is credited while it is off, and nothing already paid is touched. A referred customer who reaches the threshold meanwhile is still owed: switching back on pays the bonus. Codes typed at signup keep recording either way, so attribution is never lost. Changed only by a superadmin.',
    unit: 'toggle',
  },
  referral_reward_laari: {
    label: 'Referral reward',
    description:
      'Paid to the referrer — credited instantly to their wallet — when a referred customer’s validated spend reaches the threshold below. Once per referred customer, ever, with no time limit and no clawback. MVR 0.00 pauses payouts without forfeiting them, exactly like switching the programme off. Changed only by a superadmin.',
    unit: 'mvr',
  },
  referral_spend_threshold_laari: {
    label: 'Referral spend threshold',
    description:
      'The cumulative validated spend — purchases that survived their refund window — a referred customer must reach before the referrer’s bonus above is paid. Lowering it can make referrals already past the new bar qualify; the daily check picks them up. Changed only by a superadmin.',
    unit: 'mvr',
  },
};

const KEY_ORDER = PlatformSettingKeySchema.options;

/**
 * A warning no single key's range can carry, because it is about two keys at
 * once: the prompt-payment age window has to stay SHORTER than the settlement
 * window. Set at or beyond it, every line a merchant still owes on its due
 * date qualifies, and a discount meant to pull payment forward is simply 5%
 * off the fee for everyone. Advisory only — the value is legal, and the ranges
 * (1–15 days) already stop anything worse.
 */
function crossKeyNotice(
  key: string,
  settings: Record<string, PlatformSetting>,
): string | null {
  if (key !== 'prompt_discount_max_age_days') {
    return null;
  }

  // Both are `_days` keys, so both arrive as plain integers; Number() only
  // satisfies the union the map's value type carries for `_percent` keys.
  const window = Number(settings.settlement_due_days?.value);
  const maxAge = Number(settings.prompt_discount_max_age_days?.value);

  if (!Number.isFinite(window) || !Number.isFinite(maxAge) || maxAge < window) {
    return null;
  }

  return `At ${maxAge} days this is not shorter than the ${window}-day settlement window, so every line a merchant still owes on its due date qualifies — the discount stops rewarding promptness and becomes a standing fee cut.`;
}

export default function PlatformSettingsPage() {
  const query = useQuery({
    queryKey: ['admin', 'platform-settings'],
    queryFn: ({ signal }) => getAdminPlatformSettings({ signal }),
  });

  return (
    <div className="flex flex-col">
      <PageHeader
        title="Platform settings"
        description="Each value applies platform-wide the moment it is saved. Amounts already computed or frozen onto past transactions never change."
      />

      {query.isError ? (
        <Alert variant="destructive" appearance="light">
          <AlertIcon>
            <TriangleAlert />
          </AlertIcon>
          <AlertDescription>{apiErrorMessage(query.error)}</AlertDescription>
        </Alert>
      ) : (
        <Card className="max-w-4xl">
          <CardContent>
            {query.isPending ? (
              <div className="flex flex-col gap-4">
                {Array.from({ length: KEY_ORDER.length }).map((_, index) => (
                  <Skeleton key={index} className="h-16 w-full" />
                ))}
              </div>
            ) : (
              (() => {
                const settings = query.data.data;
                const knownKeys = KEY_ORDER.filter((key) => key in settings);
                // The server is authoritative and may add keys the client
                // does not know yet — surface them rather than hiding them.
                const extraKeys = Object.keys(settings).filter(
                  (key) => !(KEY_ORDER as readonly string[]).includes(key),
                ) as PlatformSettingKey[];

                return [...knownKeys, ...extraKeys].map((key, index) => (
                  <div key={key}>
                    {index > 0 ? <Separator /> : null}
                    <PlatformSettingRow
                      settingKey={key}
                      setting={settings[key]}
                      notice={crossKeyNotice(key, settings)}
                      meta={
                        SETTING_META[key] ?? {
                          label: key,
                          description:
                            'Integer setting (the server added this key after this UI shipped).',
                          unit: 'days',
                        }
                      }
                    />
                  </div>
                ));
              })()
            )}
          </CardContent>
        </Card>
      )}
    </div>
  );
}
