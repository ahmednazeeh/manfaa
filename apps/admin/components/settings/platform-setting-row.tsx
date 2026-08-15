'use client';

import { useEffect, useState } from 'react';
import {
  bpToPercentString,
  formatBpPercent,
  parseMvrToLaari,
  parsePercentToBp,
  percentToBp,
  updateAdminPlatformSetting,
  type PlatformSetting,
  type PlatformSettingKey,
} from '@manfaa/api-client';
import { formatMoney } from '@manfaa/ui';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { TriangleAlert } from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/lib/api-error';
import { Alert, AlertDescription, AlertIcon } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * How a key's value is entered and displayed. A `percent` key arrives from
 * the API as a 2-decimal percent STRING (basis points never travel on the
 * wire) and is worked with here as integer basis points — the same
 * integer-bp law every rate input follows, never a float in between.
 */
export type SettingUnit = 'mvr' | 'days' | 'percent';

export interface SettingMeta {
  label: string;
  /** Plain-language description of what the number does. */
  description: string;
  unit: SettingUnit;
}

/**
 * A setting value in the integer unit this component computes in: laari,
 * days, or basis points parsed out of the wire's percent string. Never a
 * float, and never a rate read as a number.
 */
function toInteger(value: number | string, unit: SettingUnit): number {
  // percentToBp, not the lenient input parser: the server always emits the
  // canonical 2-decimal form, so anything else is a contract breach.
  return unit === 'percent' ? percentToBp(String(value)) : Number(value);
}

function toInput(value: number, unit: SettingUnit): string {
  if (unit === 'days') {
    return String(value);
  }
  if (unit === 'percent') {
    return bpToPercentString(value);
  }
  // Integer laari -> "1,234.56" style MVR string, no float in between.
  const abs = Math.abs(value);
  const sign = value < 0 ? '-' : '';
  const rufiyaa = Math.trunc(abs / 100).toLocaleString('en-US');
  return `${sign}${rufiyaa}.${String(abs % 100).padStart(2, '0')}`;
}

function fromInput(raw: string, unit: SettingUnit): number | null {
  try {
    if (unit === 'days') {
      const trimmed = raw.trim();
      if (!/^\d+$/.test(trimmed)) {
        return null;
      }
      const value = Number(trimmed);
      return Number.isSafeInteger(value) ? value : null;
    }
    if (unit === 'percent') {
      return parsePercentToBp(raw);
    }
    return parseMvrToLaari(raw);
  } catch {
    return null;
  }
}

function display(value: number, unit: SettingUnit): string {
  if (unit === 'days') {
    return `${value} ${value === 1 ? 'day' : 'days'}`;
  }
  return unit === 'percent' ? formatBpPercent(value) : formatMoney(value);
}

/**
 * One platform setting: current value, plain-language meaning, allowed
 * range, and its own Save button — each key writes independently via
 * PATCH /api/admin/platform/settings/{key}.
 */
export function PlatformSettingRow({
  settingKey,
  setting,
  meta,
  notice,
}: {
  settingKey: PlatformSettingKey;
  setting: PlatformSetting;
  meta: SettingMeta;
  /**
   * A live warning about the SAVED value that no single key's range can
   * express — a setting inside its own bounds but wrong against another one.
   * Advisory: the value is legal and saving stays enabled.
   */
  notice?: string | null;
}) {
  const queryClient = useQueryClient();
  // Every comparison below runs in the key's integer unit, so a percent
  // setting is parsed out of its wire string exactly once, here.
  const current = toInteger(setting.value, meta.unit);
  const min = toInteger(setting.min, meta.unit);
  const max = toInteger(setting.max, meta.unit);
  const [raw, setRaw] = useState(() => toInput(current, meta.unit));

  // Re-sync the input when another admin's write lands via refetch.
  useEffect(() => {
    setRaw(toInput(current, meta.unit));
  }, [current, meta.unit]);

  const parsed = fromInput(raw, meta.unit);
  const outOfRange = parsed !== null && (parsed < min || parsed > max);
  const dirty = parsed !== current;
  const invalid = parsed === null || outOfRange;

  const save = useMutation({
    // The wire wants the key's own unit back: a percent string for a rate,
    // the plain integer for laari and days.
    mutationFn: (value: number) =>
      updateAdminPlatformSetting(settingKey, {
        value: meta.unit === 'percent' ? bpToPercentString(value) : value,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['admin', 'platform-settings'],
      });
      toast.success(`${meta.label} saved.`);
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const inputId = `setting-${settingKey}`;

  return (
    <div className="flex flex-col gap-3 py-4 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
      <div className="flex max-w-xl flex-col gap-1">
        <div className="flex items-center gap-2">
          <Label htmlFor={inputId} className="text-sm font-medium">
            {meta.label}
          </Label>
          {setting.overridden ? (
            <Badge variant="info" appearance="light" size="sm">
              Overridden
            </Badge>
          ) : (
            <Badge variant="secondary" appearance="light" size="sm">
              Default
            </Badge>
          )}
        </div>
        <p className="text-sm text-muted-foreground">{meta.description}</p>
        <p className="text-xs text-muted-foreground/80">
          Allowed {display(min, meta.unit)} – {display(max, meta.unit)} ·
          default {display(toInteger(setting.default, meta.unit), meta.unit)}
        </p>
        {notice ? (
          <Alert
            variant="warning"
            appearance="light"
            size="sm"
            className="mt-1"
          >
            <AlertIcon>
              <TriangleAlert />
            </AlertIcon>
            <AlertDescription>{notice}</AlertDescription>
          </Alert>
        ) : null}
      </div>
      <div className="flex shrink-0 items-start gap-2">
        <div className="flex flex-col gap-1">
          <div className="relative">
            {meta.unit === 'mvr' ? (
              <span className="pointer-events-none absolute inset-y-0 start-3 flex items-center text-sm text-muted-foreground">
                MVR
              </span>
            ) : null}
            <Input
              id={inputId}
              inputMode={meta.unit === 'days' ? 'numeric' : 'decimal'}
              value={raw}
              onChange={(event) => setRaw(event.target.value)}
              className={
                meta.unit === 'mvr'
                  ? 'w-40 ps-12'
                  : meta.unit === 'percent'
                    ? 'w-28 pe-8'
                    : 'w-28'
              }
              aria-invalid={invalid}
            />
            {meta.unit === 'percent' ? (
              <span className="pointer-events-none absolute inset-y-0 end-3 flex items-center text-sm text-muted-foreground">
                %
              </span>
            ) : null}
          </div>
          {parsed === null ? (
            <p className="text-xs text-destructive">
              {meta.unit === 'mvr'
                ? 'Enter an MVR amount, e.g. 100.00.'
                : meta.unit === 'percent'
                  ? 'Enter a percentage, e.g. 5.00.'
                  : 'Enter a whole number of days.'}
            </p>
          ) : outOfRange ? (
            <p className="text-xs text-destructive">
              Must be between {display(min, meta.unit)} and{' '}
              {display(max, meta.unit)}.
            </p>
          ) : null}
        </div>
        <Button
          size="md"
          disabled={!dirty || invalid || save.isPending}
          onClick={() => {
            if (parsed !== null) {
              save.mutate(parsed);
            }
          }}
        >
          {save.isPending ? 'Saving…' : 'Save'}
        </Button>
      </div>
    </div>
  );
}
