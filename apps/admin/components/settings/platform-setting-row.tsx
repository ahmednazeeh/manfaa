'use client';

import { useEffect, useState } from 'react';
import {
  parseMvrToLaari,
  updateAdminPlatformSetting,
  type PlatformSetting,
  type PlatformSettingKey,
} from '@manfaa/api-client';
import { formatMoney } from '@manfaa/ui';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/lib/api-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/** How a key's integer value is entered and displayed. */
export type SettingUnit = 'mvr' | 'days';

export interface SettingMeta {
  label: string;
  /** Plain-language description of what the number does. */
  description: string;
  unit: SettingUnit;
}

function toInput(value: number, unit: SettingUnit): string {
  if (unit === 'days') {
    return String(value);
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
    return parseMvrToLaari(raw);
  } catch {
    return null;
  }
}

function display(value: number, unit: SettingUnit): string {
  return unit === 'days'
    ? `${value} ${value === 1 ? 'day' : 'days'}`
    : formatMoney(value);
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
}: {
  settingKey: PlatformSettingKey;
  setting: PlatformSetting;
  meta: SettingMeta;
}) {
  const queryClient = useQueryClient();
  const [raw, setRaw] = useState(() => toInput(setting.value, meta.unit));

  // Re-sync the input when another admin's write lands via refetch.
  useEffect(() => {
    setRaw(toInput(setting.value, meta.unit));
  }, [setting.value, meta.unit]);

  const parsed = fromInput(raw, meta.unit);
  const outOfRange =
    parsed !== null && (parsed < setting.min || parsed > setting.max);
  const dirty = parsed !== setting.value;
  const invalid = parsed === null || outOfRange;

  const save = useMutation({
    mutationFn: (value: number) =>
      updateAdminPlatformSetting(settingKey, { value }),
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
          Allowed {display(setting.min, meta.unit)} –{' '}
          {display(setting.max, meta.unit)} · default{' '}
          {display(setting.default, meta.unit)}
        </p>
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
              inputMode={meta.unit === 'mvr' ? 'decimal' : 'numeric'}
              value={raw}
              onChange={(event) => setRaw(event.target.value)}
              className={meta.unit === 'mvr' ? 'w-40 ps-12' : 'w-28'}
              aria-invalid={invalid}
            />
          </div>
          {parsed === null ? (
            <p className="text-xs text-destructive">
              {meta.unit === 'mvr'
                ? 'Enter an MVR amount, e.g. 100.00.'
                : 'Enter a whole number of days.'}
            </p>
          ) : outOfRange ? (
            <p className="text-xs text-destructive">
              Must be between {display(setting.min, meta.unit)} and{' '}
              {display(setting.max, meta.unit)}.
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
