'use client';

import { useState } from 'react';
import type { MerchantSetupState } from '@manfaa/api-client';
import { LoaderCircle } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import {
  apiErrorMessage,
  onboardingErrorCode,
  useUpdateSetupLocation,
} from '@/lib/queries';
import { Button } from '@/components/ui/button';
import {
  Card,
  CardContent,
  CardFooter,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import { LocationPicker, type LatLng } from '@/components/app/location-picker';

/**
 * The wizard's location step (D14, between profile and logo): one pin on the
 * store's primary branch, which the server creates under the store's own name
 * if the store has none yet.
 *
 * Required to continue for a store with a counter, skippable for an
 * online-only one — and skippable for everybody when the map itself cannot
 * load, because a missing key or a blocked script must not leave an owner
 * unable to finish signing up. The pin is never a submit requirement (D17).
 */

/** The pin on file. A branch without coordinates is a branch, not a pin. */
export function pinnedLocation(state: MerchantSetupState): LatLng | null {
  const branch = state.values.primary_branch;

  return branch !== null && branch.lat !== null && branch.lng !== null
    ? { lat: branch.lat, lng: branch.lng }
    : null;
}

/**
 * Whether this step may be passed without a pin. Read from the channel the
 * SERVER holds, never from the profile step's radio: that radio is unsaved
 * until its own Continue, and a channel the owner is merely considering must
 * not decide whether a later step blocks them.
 */
export function locationRequired(state: MerchantSetupState): boolean {
  return state.values.channel !== 'online';
}

export function LocationStep({
  state,
  onDone,
  onBack,
}: {
  state: MerchantSetupState;
  onDone: () => void;
  onBack: () => void;
}) {
  const { t } = useTranslation();
  const updateLocation = useUpdateSetupLocation();

  const saved = pinnedLocation(state);
  const [pin, setPin] = useState<LatLng | null>(saved);
  const [mapUnavailable, setMapUnavailable] = useState(false);

  const required = locationRequired(state);
  const mayPassUnpinned = !required || mapUnavailable;
  const canContinue =
    !updateLocation.isPending && (pin !== null || mayPassUnpinned);

  const save = () => {
    if (pin === null) {
      if (mayPassUnpinned) onDone();
      return;
    }
    // Nothing moved since the last visit — the owner is passing back through
    // the step, not re-pinning. Plain navigation, no write.
    if (saved !== null && pin.lat === saved.lat && pin.lng === saved.lng) {
      onDone();
      return;
    }
    updateLocation.mutate(pin, {
      onSuccess: () => {
        toast.success(t('setup.locationSaved'));
        onDone();
      },
      onError: (error) => {
        toast.error(
          onboardingErrorCode(error) === 'setup_not_editable'
            ? t('setup.notEditable')
            : apiErrorMessage(error, t('common.serverUnreachable')),
        );
      },
    });
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('setup.locationTitle')}</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <p className="text-sm text-muted-foreground">
          {required ? t('setup.locationSubtitle') : t('setup.locationOnline')}
        </p>

        <LocationPicker
          value={pin}
          onChange={setPin}
          onUnavailable={() => setMapUnavailable(true)}
        />

        {mapUnavailable ? (
          <p className="text-xs text-muted-foreground">
            {t('setup.locationLater')}
          </p>
        ) : (
          required &&
          pin === null && (
            <p className="text-xs text-muted-foreground">
              {t('setup.locationNeeded')}
            </p>
          )
        )}
      </CardContent>
      <CardFooter className="justify-between">
        <Button variant="ghost" onClick={onBack}>
          {t('common.back')}
        </Button>
        <Button
          variant={pin !== null ? 'primary' : 'outline'}
          disabled={!canContinue}
          onClick={save}
        >
          {updateLocation.isPending && (
            <LoaderCircle className="animate-spin" />
          )}
          {pin !== null || !mayPassUnpinned
            ? t('common.continue')
            : t('common.skip')}
        </Button>
      </CardFooter>
    </Card>
  );
}
