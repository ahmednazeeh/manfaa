'use client';

import {
  getAdminPlatformSettings,
  PlatformSettingKeySchema,
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
 * Plain-language meaning of each key. Values are integers server-side
 * (laari or days); the row component converts to and from MVR strings
 * without ever passing money through a float.
 */
const SETTING_META: Record<PlatformSettingKey, SettingMeta> = {
  min_payout_laari: {
    label: 'Minimum payout',
    description:
      'Confirmed balances below this carry to the next month instead of being paid out. Closing an account always pays the full balance regardless.',
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
    label: 'Default validation window',
    description:
      'Refund window applied to newly onboarded merchants; the settlement clock starts only when it closes. Adjustable per merchant afterwards.',
    unit: 'days',
  },
  default_min_eligible_laari: {
    label: 'Default minimum eligible sale',
    description:
      'Sales below this earn no cashback — they are still recorded, with a below-minimum reason, so the till sees something truthful. Applied to new merchants.',
    unit: 'mvr',
  },
};

const KEY_ORDER = PlatformSettingKeySchema.options;

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
                {Array.from({ length: 5 }).map((_, index) => (
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
