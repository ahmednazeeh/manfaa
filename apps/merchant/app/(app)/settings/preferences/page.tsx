'use client';

import { useEffect, useState } from 'react';
import {
  formatLaari,
  parseMvrToLaari,
  type MerchantPreferences,
  type SettlementFundingMethod,
} from '@manfaa/api-client';
import { Info, LoaderCircle } from 'lucide-react';
import {
  apiErrorMessage,
  usePreferences,
  useUpdatePreferences,
} from '@/lib/queries';
import {
  Alert,
  AlertContent,
  AlertDescription,
  AlertIcon,
  AlertTitle,
} from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
  Card,
  CardContent,
  CardFooter,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import { Input, InputAddon, InputGroup } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { toast } from 'sonner';
import {
  Toolbar,
  ToolbarDescription,
  ToolbarHeading,
  ToolbarPageTitle,
} from '@/components/app-layout/toolbar';
import { ErrorBlock, LoadingBlock } from '@/components/app/async-states';

/** Platform bounds (mirrors the API's validation; the server re-checks). */
const MIN_ELIGIBLE_MAX_LAARI = 100000; // MVR 1,000
// The validation-window ceiling is dynamic: the admin-governed platform
// window, served as preferences.validation_window_max_days (§11 — the
// stale-review window is not merchant-raisable).

function safeParseMvr(input: string): number | null {
  try {
    return parseMvrToLaari(input);
  } catch {
    return null;
  }
}

function PreferencesForm({
  preferences,
}: {
  preferences: MerchantPreferences;
}) {
  const updatePreferences = useUpdatePreferences();

  const [method, setMethod] = useState<SettlementFundingMethod>(
    preferences.settlement_method,
  );
  const [minEligibleInput, setMinEligibleInput] = useState(
    // laari -> "12.50"-style MVR string, integer arithmetic only.
    formatLaari(preferences.min_eligible_laari).replace('MVR ', ''),
  );
  const [windowInput, setWindowInput] = useState(
    String(preferences.validation_window_days),
  );

  useEffect(() => {
    setMethod(preferences.settlement_method);
    setMinEligibleInput(
      formatLaari(preferences.min_eligible_laari).replace('MVR ', ''),
    );
    setWindowInput(String(preferences.validation_window_days));
  }, [preferences]);

  const minEligibleLaari = safeParseMvr(minEligibleInput);
  const minEligibleInvalid =
    minEligibleLaari === null ||
    minEligibleLaari < 0 ||
    minEligibleLaari > MIN_ELIGIBLE_MAX_LAARI;

  const windowMaxDays = preferences.validation_window_max_days;

  const windowDays = /^\d{1,2}$/.test(windowInput.trim())
    ? Number(windowInput.trim())
    : null;
  const windowInvalid =
    windowDays === null || windowDays < 0 || windowDays > windowMaxDays;

  const canSave =
    !minEligibleInvalid && !windowInvalid && !updatePreferences.isPending;

  const save = () => {
    if (minEligibleLaari === null || windowDays === null) return;
    updatePreferences.mutate(
      {
        settlement_method: method,
        min_eligible_laari: minEligibleLaari,
        validation_window_days: windowDays,
      },
      {
        onSuccess: () => toast.success('Preferences saved'),
        onError: (error) =>
          toast.error(
            apiErrorMessage(error, 'Could not save the preferences.'),
          ),
      },
    );
  };

  return (
    <div className="max-w-xl flex flex-col gap-5 pb-7.5">
      <Alert variant="info" appearance="light">
        <AlertIcon>
          <Info />
        </AlertIcon>
        <AlertContent>
          <AlertTitle>Changes apply to future sales only.</AlertTitle>
          <AlertDescription>
            Every recorded sale keeps the terms that were in force at its
            sale time — history never moves.
          </AlertDescription>
        </AlertContent>
      </Alert>

      <Card>
        <CardHeader>
          <CardTitle>Earning &amp; settlement</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-5">
          <div className="flex flex-col gap-2.5">
            <Label htmlFor="settlement-method">Settlement funding</Label>
            <Select
              value={method}
              onValueChange={(value) =>
                setMethod(value as SettlementFundingMethod)
              }
            >
              <SelectTrigger id="settlement-method" className="w-56">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="bank">Bank transfer</SelectItem>
                <SelectItem value="wallet">Wallet balance</SelectItem>
              </SelectContent>
            </Select>
            <p className="text-xs text-muted-foreground">
              How your settlements are funded by default: a bank transfer
              using our payment instructions, or your Manfaa wallet balance.
              Either way the batches, deadlines and records are identical.
            </p>
          </div>

          <div className="flex flex-col gap-2.5">
            <Label htmlFor="min-eligible">Minimum eligible sale</Label>
            <InputGroup className="w-56">
              <InputAddon>MVR</InputAddon>
              <Input
                id="min-eligible"
                inputMode="decimal"
                value={minEligibleInput}
                aria-invalid={minEligibleInvalid}
                onChange={(event) => setMinEligibleInput(event.target.value)}
              />
            </InputGroup>
            {minEligibleInvalid ? (
              <p className="text-xs text-destructive">
                Enter an amount between MVR 0 and MVR 1,000.
              </p>
            ) : (
              <p className="text-xs text-muted-foreground">
                Sales below this amount earn no cashback. They are still
                recorded — with zero reward — so the books stay complete.
                Platform bound: MVR 0 to MVR 1,000.
              </p>
            )}
          </div>

          <div className="flex flex-col gap-2.5">
            <Label htmlFor="validation-window">Validation window</Label>
            <InputGroup className="w-56">
              <Input
                id="validation-window"
                inputMode="numeric"
                value={windowInput}
                aria-invalid={windowInvalid}
                onChange={(event) => setWindowInput(event.target.value)}
              />
              <InputAddon>days</InputAddon>
            </InputGroup>
            {windowInvalid ? (
              <p className="text-xs text-destructive">
                Enter a whole number of days between 0 and {windowMaxDays}.
              </p>
            ) : (
              <p className="text-xs text-muted-foreground">
                How long a sale can still be refunded or voided before its
                cashback becomes payable. Your 15-day settlement clock starts
                when this window closes. Platform bound: 0 to {windowMaxDays}{' '}
                days.
              </p>
            )}
          </div>
        </CardContent>
        <CardFooter className="justify-end">
          <Button disabled={!canSave} onClick={save}>
            {updatePreferences.isPending && (
              <LoaderCircle className="animate-spin" />
            )}
            Save preferences
          </Button>
        </CardFooter>
      </Card>
    </div>
  );
}

export default function PreferencesSettingsPage() {
  const preferences = usePreferences();

  return (
    <div className="container">
      <Toolbar>
        <ToolbarHeading>
          <ToolbarPageTitle>Preferences</ToolbarPageTitle>
          <ToolbarDescription>
            How your store earns and settles
          </ToolbarDescription>
        </ToolbarHeading>
      </Toolbar>

      {preferences.error ? (
        <ErrorBlock error={preferences.error} />
      ) : !preferences.data ? (
        <LoadingBlock lines={5} />
      ) : (
        <PreferencesForm preferences={preferences.data} />
      )}
    </div>
  );
}
