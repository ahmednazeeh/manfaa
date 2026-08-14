'use client';

import { useState } from 'react';
import { parsePercentToBp } from '@manfaa/api-client';
import { format } from 'date-fns';
import {
  CircleCheck,
  Clock4,
  Info,
  LoaderCircle,
  TriangleAlert,
} from 'lucide-react';
import { type MerchantRate, type RateChangeSummary } from '@/lib/api';
import { formatBp } from '@/lib/estimate';
import {
  apiErrorMessage,
  isRateNotPriced,
  rateNotPricedMessage,
  useChangeRate,
  useRate,
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
import { toast } from 'sonner';
import {
  Toolbar,
  ToolbarDescription,
  ToolbarHeading,
  ToolbarPageTitle,
} from '@/components/app-layout/toolbar';
import { ErrorBlock, LoadingBlock } from '@/components/app/async-states';

/**
 * The standing-rate screen (owner only via the settings layout gate; the
 * API re-enforces 403). The merchant thinks in PERCENT: the input takes an
 * exact-2dp percent string, converted to integer basis points with the
 * shared parsePercentToBp helper — string decomposition, never a float —
 * and every displayed rate goes back through the percent formatter.
 *
 * §7 timing rules the copy must be honest about: increases apply
 * immediately, decreases at the next business-day midnight (a stale till
 * can never over-promise), and a new change replaces a scheduled one.
 */

/** §4 structural bounds, integer bp. The active fee schedule may end lower. */
const MIN_RATE_BP = 50;
const MAX_RATE_BP = 2000;

/**
 * The §4 static fee bands, used ONLY for the pre-submit tier-cliff hint.
 * The platform's fee tier schedule is authoritative — the server confirms
 * the actual fee in the change summary (and refuses unpriced rates).
 */
const STATIC_FEE_BANDS: ReadonlyArray<{ to: number; fee: number }> = [
  { to: 99, fee: 25 },
  { to: 199, fee: 50 },
  { to: 499, fee: 75 },
  { to: 2000, fee: 100 },
];

function staticFeeBp(rateBp: number): number {
  const band = STATIC_FEE_BANDS.find(({ to }) => rateBp <= to);
  return (band ?? STATIC_FEE_BANDS[STATIC_FEE_BANDS.length - 1]).fee;
}

function RateWindowCard({ rate }: { rate: MerchantRate }) {
  const { current, pending } = rate;

  return (
    <Card>
      <CardHeader>
        <CardTitle>Current terms</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        {current === null ? (
          <p className="text-sm text-muted-foreground">
            No standing rate yet — set one below to start offering cashback.
          </p>
        ) : (
          <div className="grid grid-cols-3 gap-4">
            <div>
              <div className="text-xs text-muted-foreground">
                Customer cashback
              </div>
              <div className="text-2xl font-semibold">
                {formatBp(current.rate_bp)}
              </div>
            </div>
            <div>
              <div className="text-xs text-muted-foreground">Platform fee</div>
              {/* Null fee: a stranded legacy rate the fee schedule no longer
                  prices — the page must still render so the owner can lower
                  the rate back into the priced range. */}
              <div className="text-2xl font-semibold">
                {current.fee_bp === null ? '—' : formatBp(current.fee_bp)}
              </div>
            </div>
            <div>
              <div className="text-xs text-muted-foreground">
                You pay per sale
              </div>
              <div className="text-2xl font-semibold">
                {current.all_in_bp === null ? '—' : formatBp(current.all_in_bp)}
              </div>
            </div>
          </div>
        )}
        {current !== null && (
          <p className="text-xs text-muted-foreground">
            In force since{' '}
            {format(new Date(current.effective_from), 'dd MMM yyyy, HH:mm')}.
            Every recorded sale keeps the rate that was in force at its sale
            time.
          </p>
        )}
        {pending !== null && (
          <Alert variant="info" appearance="light">
            <AlertIcon>
              <Clock4 />
            </AlertIcon>
            <AlertContent>
              <AlertTitle>
                Scheduled change: {formatBp(pending.rate_bp)} cashback
              </AlertTitle>
              <AlertDescription>
                Takes effect{' '}
                {format(new Date(pending.effective_from), 'dd MMM yyyy, HH:mm')}
                {pending.fee_bp !== null && pending.all_in_bp !== null
                  ? ` — platform fee ${formatBp(pending.fee_bp)}, all-in ${formatBp(pending.all_in_bp)}`
                  : ''}
                . Submitting a new change below replaces it.
              </AlertDescription>
            </AlertContent>
          </Alert>
        )}
      </CardContent>
    </Card>
  );
}

function ChangeSummaryAlert({ change }: { change: RateChangeSummary }) {
  return (
    <Alert variant="success" appearance="light">
      <AlertIcon>
        <CircleCheck />
      </AlertIcon>
      <AlertContent>
        <AlertTitle>
          Cashback rate {change.applies === 'immediately' ? 'is now' : 'will be'}{' '}
          {formatBp(change.new.rate_bp)}
        </AlertTitle>
        <AlertDescription>
          {change.applies === 'immediately'
            ? 'Applied immediately.'
            : `Takes effect ${format(
                new Date(change.effective_at),
                'dd MMM yyyy, HH:mm',
              )} — the advertised rate is honoured until then.`}{' '}
          {change.tier_changed &&
          change.previous.fee_bp !== null &&
          change.previous.all_in_bp !== null
            ? `This moved your fee tier from ${formatBp(
                change.previous.fee_bp,
              )} to ${formatBp(change.new.fee_bp)} — all-in cost ${formatBp(
                change.previous.all_in_bp,
              )} to ${formatBp(change.new.all_in_bp)}.`
            : `Platform fee ${
                change.tier_changed ? 'is now' : 'stays at'
              } ${formatBp(
                change.new.fee_bp,
              )} — all-in cost ${formatBp(change.new.all_in_bp)}.`}
        </AlertDescription>
      </AlertContent>
    </Alert>
  );
}

function RateChangeForm({ rate }: { rate: MerchantRate }) {
  const changeRate = useChangeRate();
  const current = rate.current;

  const [input, setInput] = useState('');
  const [summary, setSummary] = useState<RateChangeSummary | null>(null);
  const [notPriced, setNotPriced] = useState<string | null>(null);

  const trimmed = input.trim();
  const newBp = trimmed === '' ? null : parsePercentToBp(trimmed);

  const inputError =
    trimmed === ''
      ? null
      : newBp === null
        ? 'Enter a percent with up to two decimal places, e.g. 7.50.'
        : newBp < MIN_RATE_BP
          ? `The minimum cashback rate is ${formatBp(MIN_RATE_BP)}.`
          : newBp > MAX_RATE_BP
            ? `The maximum cashback rate is ${formatBp(MAX_RATE_BP)}.`
            : null;

  const validBp = inputError === null ? newBp : null;
  const isSameAsCurrent =
    validBp !== null && current !== null && validBp === current.rate_bp;

  // Pre-submit tier-cliff hint from the §4 static bands; the FROM side is
  // the authoritative current fee. The server confirms the actual fee on
  // apply (the schedule governs) and refuses rates it does not price.
  const prospectiveFee = validBp === null ? null : staticFeeBp(validBp);
  const tierCliff =
    validBp !== null &&
    prospectiveFee !== null &&
    current !== null &&
    current.fee_bp !== null &&
    !isSameAsCurrent &&
    prospectiveFee !== current.fee_bp;

  // A same-rate submit is a no-op — except when a change is scheduled, where
  // the API treats it as "keep my current rate": the pending row is replaced.
  const cancelsPending = isSameAsCurrent && rate.pending !== null;
  const canSubmit =
    validBp !== null &&
    (!isSameAsCurrent || cancelsPending) &&
    !changeRate.isPending;

  const submit = () => {
    if (validBp === null) return;
    setNotPriced(null);
    changeRate.mutate(validBp, {
      onSuccess: (response) => {
        setSummary(response.change);
        setInput('');
        toast.success('Cashback rate updated');
      },
      onError: (error) => {
        setSummary(null);
        if (isRateNotPriced(error)) {
          setNotPriced(rateNotPricedMessage(error));
        } else {
          toast.error(apiErrorMessage(error, 'Could not change the rate.'));
        }
      },
    });
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle>Change the rate</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-5">
        <div className="flex flex-col gap-2.5">
          <Label htmlFor="new-rate">New customer cashback rate</Label>
          <InputGroup className="w-56">
            <Input
              id="new-rate"
              inputMode="decimal"
              placeholder={
                current === null ? 'e.g. 5.00' : formatBp(current.rate_bp).replace('%', '')
              }
              value={input}
              aria-invalid={inputError !== null}
              onChange={(event) => {
                setInput(event.target.value);
                setNotPriced(null);
              }}
            />
            <InputAddon>%</InputAddon>
          </InputGroup>
          {inputError !== null ? (
            <p className="text-xs text-destructive">{inputError}</p>
          ) : (
            <p className="text-xs text-muted-foreground">
              Percent of the eligible amount, up to two decimal places.
              Allowed range {formatBp(MIN_RATE_BP)} to {formatBp(MAX_RATE_BP)}.
              Fee bands: up to 0.99% cashback → 0.25% fee · 1–1.99% → 0.5% ·
              2–4.99% → 0.75% · 5–20% → 1%.
            </p>
          )}
          {isSameAsCurrent && rate.pending === null && (
            <p className="text-xs text-muted-foreground">
              This is already your current rate.
            </p>
          )}
          {cancelsPending && (
            <p className="text-xs text-muted-foreground">
              This matches your current rate — submitting cancels the
              scheduled change and keeps the rate as it is.
            </p>
          )}
        </div>

        {tierCliff &&
          current !== null &&
          current.fee_bp !== null &&
          current.all_in_bp !== null &&
          prospectiveFee !== null &&
          validBp !== null && (
          <Alert variant="warning" appearance="light">
            <AlertIcon>
              <TriangleAlert />
            </AlertIcon>
            <AlertContent>
              <AlertTitle>This change moves your fee tier.</AlertTitle>
              <AlertDescription>
                {validBp > current.rate_bp ? 'Raising' : 'Lowering'} from{' '}
                {formatBp(current.rate_bp)} to {formatBp(validBp)} moves your
                fee tier from {formatBp(current.fee_bp)} to{' '}
                {formatBp(prospectiveFee)} — your all-in cost goes from{' '}
                {formatBp(current.all_in_bp)} to{' '}
                {formatBp(validBp + prospectiveFee)} of each eligible sale.
                The platform&apos;s fee schedule confirms the exact fee when
                the change is applied.
              </AlertDescription>
            </AlertContent>
          </Alert>
        )}

        {validBp !== null && current !== null && !isSameAsCurrent && (
          <Alert variant="info" appearance="light">
            <AlertIcon>
              <Info />
            </AlertIcon>
            <AlertContent>
              <AlertTitle>
                {validBp > current.rate_bp
                  ? 'Increases apply immediately.'
                  : 'Decreases apply at midnight tonight.'}
              </AlertTitle>
              <AlertDescription>
                {validBp > current.rate_bp
                  ? 'The higher rate starts with the next recorded sale.'
                  : 'The current rate is honoured until 00:00 (Maldives time), so an advertised rate is never cut mid-day.'}
              </AlertDescription>
            </AlertContent>
          </Alert>
        )}

        {notPriced !== null && (
          <Alert variant="destructive" appearance="light">
            <AlertIcon>
              <TriangleAlert />
            </AlertIcon>
            <AlertContent>
              <AlertTitle>Rate not available</AlertTitle>
              <AlertDescription>{notPriced}</AlertDescription>
            </AlertContent>
          </Alert>
        )}

        {summary !== null && <ChangeSummaryAlert change={summary} />}
      </CardContent>
      <CardFooter className="justify-end">
        <Button disabled={!canSubmit} onClick={submit}>
          {changeRate.isPending && <LoaderCircle className="animate-spin" />}
          {current === null
            ? 'Set rate'
            : cancelsPending
              ? 'Cancel scheduled change'
              : 'Change rate'}
        </Button>
      </CardFooter>
    </Card>
  );
}

export default function RateSettingsPage() {
  const rate = useRate();

  return (
    <div className="container">
      <Toolbar>
        <ToolbarHeading>
          <ToolbarPageTitle>Cashback rate</ToolbarPageTitle>
          <ToolbarDescription>
            The standing rate every sale earns, and what it costs you
          </ToolbarDescription>
        </ToolbarHeading>
      </Toolbar>

      {rate.error ? (
        <ErrorBlock error={rate.error} />
      ) : !rate.data ? (
        <LoadingBlock lines={4} />
      ) : (
        <div className="max-w-xl flex flex-col gap-5 pb-7.5">
          <RateWindowCard rate={rate.data} />
          <RateChangeForm rate={rate.data} />
        </div>
      )}
    </div>
  );
}
