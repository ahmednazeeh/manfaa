'use client';

import {
  createContext,
  useContext,
  type HTMLAttributes,
  type ReactNode,
} from 'react';

/**
 * How money is spelled in the active language.
 *
 * The AMOUNT never varies — integer laari, grouped, two decimals, Western
 * digits (the Maldives writes numerals that way in both languages). Only
 * the currency word and its side change:
 *
 *   en  MVR 1,234.56
 *   dv  1,234.56 ރުފިޔާ
 *
 * "MVR" is an ISO code for banking systems, not a word a Dhivehi reader
 * uses; on a Thaana page it reads as untranslated UI.
 */
export interface MoneyLocale {
  /** The currency word, e.g. "MVR" or "ރުފިޔާ". */
  label: string;
  /** Which side of the amount the label sits on. */
  placement: 'before' | 'after';
}

export const DEFAULT_MONEY_LOCALE: MoneyLocale = {
  label: 'MVR',
  placement: 'before',
};

const MoneyLocaleContext = createContext<MoneyLocale>(DEFAULT_MONEY_LOCALE);

/**
 * Supplies the money spelling to every MoneyText below it. Apps mount this
 * from their language provider; anything rendered outside one keeps the
 * English default, so a component can never crash for want of a provider.
 */
export function MoneyLocaleProvider({
  locale,
  children,
}: {
  locale: MoneyLocale;
  children: ReactNode;
}) {
  return (
    <MoneyLocaleContext.Provider value={locale}>
      {children}
    </MoneyLocaleContext.Provider>
  );
}

export function useMoneyLocale(): MoneyLocale {
  return useContext(MoneyLocaleContext);
}

export interface MoneyTextProps extends HTMLAttributes<HTMLSpanElement> {
  /** Amount as an integer count of laari (1 rufiyaa = 100 laari). Never a float. */
  laari: number;
  /**
   * The amount's ISO currency code, straight from the API. v1 is MVR-only,
   * and for MVR the SPELLING is a language question, so the locale wins.
   * Anything else wins over the locale instead — money in another currency
   * must never be relabelled "ރުފިޔާ" just because the reader is Dhivehi.
   */
  currency?: string;
  /** Overrides the spelling outright — rarely needed. */
  locale?: MoneyLocale;
}

/** MVR is spelled by the language; any other currency speaks for itself. */
export function resolveMoneyLocale(
  locale: MoneyLocale,
  currency?: string,
): MoneyLocale {
  if (currency === undefined || currency === '' || currency === 'MVR') {
    return locale;
  }

  return { label: currency, placement: 'before' };
}

/**
 * Formats integer laari as a money string using integer arithmetic only;
 * Intl is used solely to group the rufiyaa part.
 *
 * The second parameter accepts either a plain currency string (the original
 * signature, still used by non-React callers) or a MoneyLocale.
 */
export function formatMoney(
  laari: number,
  locale: MoneyLocale | string = DEFAULT_MONEY_LOCALE,
): string {
  if (!Number.isSafeInteger(laari)) {
    throw new TypeError(`laari must be a safe integer, got ${laari}`);
  }

  const { label, placement }: MoneyLocale =
    typeof locale === 'string' ? { label: locale, placement: 'before' } : locale;

  const sign = laari < 0 ? '-' : '';
  const abs = Math.abs(laari);
  const rufiyaa = Math.trunc(abs / 100);
  const remainder = abs % 100;
  const grouped = new Intl.NumberFormat('en-US').format(rufiyaa);
  // LEFT-TO-RIGHT ISOLATE … POP DIRECTIONAL ISOLATE around the digits. The
  // amount is a directionally neutral run, so inside a Dhivehi sentence the
  // bidi algorithm would otherwise be free to move the minus sign to the
  // far end. The isolate travels with the STRING, so it survives being
  // interpolated into a translated sentence where no wrapper element can
  // reach — unlike MoneyText's dir="ltr", which only helps its own span.
  // The currency word stays outside it and keeps its own direction.
  const amount = `\u2066${sign}${grouped}.${String(remainder).padStart(2, '0')}\u2069`;

  return placement === 'after' ? `${amount} ${label}` : `${label} ${amount}`;
}

/**
 * Formats money in the active language. Use this wherever the plain
 * `formatMoney` would otherwise be called from a component — it is the only
 * way a string built by hand picks up the Dhivehi spelling.
 */
export function useFormatMoney(): (laari: number, currency?: string) => string {
  const locale = useMoneyLocale();
  return (laari: number, currency?: string) =>
    formatMoney(laari, resolveMoneyLocale(locale, currency));
}

/**
 * Renders an integer laari amount with tabular numerals so columns of money
 * line up. Display-only — arithmetic on money stays in integer laari.
 */
export function MoneyText({
  laari,
  currency,
  locale,
  className,
  ...props
}: MoneyTextProps) {
  const contextLocale = useMoneyLocale();
  const active = locale ?? resolveMoneyLocale(contextLocale, currency);

  // The container's direction decides which SIDE the currency word lands
  // on; the digits are already protected by the isolate inside
  // formatMoney, so they keep their order either way.
  //
  // English says "MVR 1,240.00" and reads left to right, so the word is
  // leftmost. Dhivehi says the amount and THEN the unit, and reads right to
  // left — so the number is rightmost and ރުފިޔާ sits to its left. Forcing
  // ltr here rendered the Dhivehi form in English reading order, which put
  // ރުފިޔާ on the wrong side of every figure in the app.
  const direction = active.placement === 'after' ? 'rtl' : 'ltr';

  return (
    <span
      dir={direction}
      className={['tabular-nums', className].filter(Boolean).join(' ')}
      {...props}
    >
      {formatMoney(laari, active)}
    </span>
  );
}
