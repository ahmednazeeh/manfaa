'use client';

import { useState } from 'react';
import { parsePercentToBp, percentToBp } from '@manfaa/api-client';
import { format } from 'date-fns';
import {
  CircleCheck,
  Clock4,
  Info,
  LoaderCircle,
  TriangleAlert,
} from 'lucide-react';
import { type MerchantRate, type RateChangeSummary } from '@/lib/api';
import {
  formatBp,
  formatRate,
  formatRateOrDash,
  trimRate,
} from '@/lib/estimate';
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
 * API re-enforces 403). Everything on this page is a PERCENT: the API sends
 * rates as 2-decimal percent strings (PLAN §1 wire format) and they are
 * displayed as they arrive, trimmed. Basis points appear only where this
 * page does its own arithmetic — the range check on what the owner typed
 * and the §4 tier-cliff comparison — via the shared exact-2dp string
 * parsers, never a float.
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
                {formatRate(current.cashback_rate_percent)}
              </div>
            </div>
            <div>
              <div className="text-xs text-muted-foreground">Platform fee</div>
              {/* Null fee: a stranded legacy rate the fee schedule no longer
                  prices — the page must still render so the owner can lower
                  the rate back into the priced range. */}
              <div className="text-2xl font-semibold">
                {formatRateOrDash(current.platform_fee_percent)}
              </div>
            </div>
            <div>
              <div className="text-xs text-muted-foreground">
                You pay per sale
              </div>
              <div className="text-2xl font-semibold">
                {formatRateOrDash(current.all_in_percent)}
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
                Scheduled change: {formatRate(pending.cashback_rate_percent)}{' '}
                cashback
              </AlertTitle>
              <AlertDescription>
                Takes effect{' '}
                {format(new Date(pending.effective_from), 'dd MMM yyyy, HH:mm')}
                {pending.platform_fee_percent !== null &&
                pending.all_in_percent !== null
                  ? ` — platform fee ${formatRate(pending.platform_fee_percent)}, all-in ${formatRate(pending.all_in_percent)}`
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
          {formatRate(change.new.cashback_rate_percent)}
        </AlertTitle>
        <AlertDescription>
          {change.applies === 'immediately'
            ? 'Applied immediately.'
            : `Takes effect ${format(
                new Date(change.effective_at),
                'dd MMM yyyy, HH:mm',
              )} — the advertised rate is honoured until then.`}{' '}
          {change.tier_changed &&
          change.previous.platform_fee_percent !== null &&
          change.previous.all_in_percent !== null
            ? `This moved your fee tier from ${formatRate(
                change.previous.platform_fee_percent,
              )} to ${formatRate(
                change.new.platform_fee_percent,
              )} — all-in cost ${formatRate(
                change.previous.all_in_percent,
              )} to ${formatRate(change.new.all_in_percent)}.`
            : `Platform fee ${
                change.tier_changed ? 'is now' : 'stays at'
              } ${formatRate(
                change.new.platform_fee_percent,
              )} — all-in cost ${formatRate(change.new.all_in_percent)}.`}
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

  /**
   * The page's OWN arithmetic — the range check, the "already your rate"
   * test, the §4 tier-cliff comparison — is integer basis points, because
   * "4.99" and "5.00" are one tier apart and text cannot see that. The
   * API's percent strings are converted here, once; every rate SHOWN below
   * is the string the server sent.
   */
  const currentBp =
    current === null ? null : percentToBp(current.cashback_rate_percent);
  const currentFeeBp =
    current === null || current.platform_fee_percent === null
      ? null
      : percentToBp(current.platform_fee_percent);

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
    validBp !== null && currentBp !== null && validBp === currentBp;

  // Pre-submit tier-cliff hint from the §4 static bands; the FROM side is
  // the authoritative current fee. The server confirms the actual fee on
  // apply (the schedule governs) and refuses rates it does not price.
  const prospectiveFee = validBp === null ? null : staticFeeBp(validBp);
  const tierCliff =
    validBp !== null &&
    prospectiveFee !== null &&
    currentFeeBp !== null &&
    !isSameAsCurrent &&
    prospectiveFee !== currentFeeBp;

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
                current === null
                  ? 'e.g. 5.00'
                  : trimRate(current.cashback_rate_percent)
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
          currentBp !== null &&
          current.platform_fee_percent !== null &&
          current.all_in_percent !== null &&
          prospectiveFee !== null &&
          validBp !== null && (
          <Alert variant="warning" appearance="light">
            <AlertIcon>
              <TriangleAlert />
            </AlertIcon>
            <AlertContent>
              <AlertTitle>This change moves your fee tier.</AlertTitle>
              <AlertDescription>
                {validBp > currentBp ? 'Raising' : 'Lowering'} from{' '}
                {formatRate(current.cashback_rate_percent)} to{' '}
                {formatBp(validBp)} moves your fee tier from{' '}
                {formatRate(current.platform_fee_percent)} to{' '}
                {formatBp(prospectiveFee)} — your all-in cost goes from{' '}
                {formatRate(current.all_in_percent)} to{' '}
                {formatBp(validBp + prospectiveFee)} of each eligible sale.
                The platform&apos;s fee schedule confirms the exact fee when
                the change is applied.
              </AlertDescription>
            </AlertContent>
          </Alert>
        )}

        {validBp !== null && currentBp !== null && !isSameAsCurrent && (
          <Alert variant="info" appearance="light">
            <AlertIcon>
              <Info />
            </AlertIcon>
            <AlertContent>
              <AlertTitle>
                {validBp > currentBp
                  ? 'Increases apply immediately.'
                  : 'Decreases apply at midnight tonight.'}
              </AlertTitle>
              <AlertDescription>
                {validBp > currentBp
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
