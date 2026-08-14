import type { HTMLAttributes } from 'react';

export interface MoneyTextProps extends HTMLAttributes<HTMLSpanElement> {
  /** Amount as an integer count of laari (1 MVR = 100 laari). Never a float. */
  laari: number;
  /** ISO currency code. v1 is MVR-only. */
  currency?: string;
}

/**
 * Formats integer laari as a currency string (e.g. 123456 -> "MVR 1,234.56")
 * using integer arithmetic only; Intl is used solely to group the rufiyaa part.
 */
export function formatMoney(laari: number, currency = 'MVR'): string {
  if (!Number.isSafeInteger(laari)) {
    throw new TypeError(`laari must be a safe integer, got ${laari}`);
  }
  const sign = laari < 0 ? '-' : '';
  const abs = Math.abs(laari);
  const rufiyaa = Math.trunc(abs / 100);
  const remainder = abs % 100;
  const grouped = new Intl.NumberFormat('en-US').format(rufiyaa);
  return `${sign}${currency} ${grouped}.${String(remainder).padStart(2, '0')}`;
}

/**
 * Renders an integer laari amount as MVR with tabular numerals so columns of
 * money line up. Display-only — arithmetic on money stays in integer laari.
 */
export function MoneyText({
  laari,
  currency = 'MVR',
  className,
  ...props
}: MoneyTextProps) {
  return (
    <span
      className={['tabular-nums', className].filter(Boolean).join(' ')}
      {...props}
    >
      {formatMoney(laari, currency)}
    </span>
  );
}
