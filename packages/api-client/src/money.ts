/**
 * Display helpers between integer laari and MVR strings.
 *
 * All arithmetic stays in integer laari (1 MVR = 100 laari) — no float math
 * on money, matching the API's bigint representation.
 */

/** Formats integer laari as MVR with 2dp, e.g. 123456 -> "MVR 1,234.56". */
export function formatLaari(laari: number): string {
  if (!Number.isSafeInteger(laari)) {
    throw new TypeError(`laari must be a safe integer, got ${laari}`);
  }
  const sign = laari < 0 ? '-' : '';
  const abs = Math.abs(laari);
  const rufiyaa = Math.trunc(abs / 100);
  const remainder = abs % 100;
  const grouped = new Intl.NumberFormat('en-US').format(rufiyaa);
  return `${sign}MVR ${grouped}.${String(remainder).padStart(2, '0')}`;
}

/**
 * Parses a user-entered MVR amount ("1,234.56", "-50", "12.5") into integer
 * laari via string decomposition — the input never passes through a float.
 */
export function parseMvrToLaari(input: string): number {
  const normalized = input.trim().replace(/,/g, '');
  const match = /^(-?)(\d+)(?:\.(\d{1,2}))?$/.exec(normalized);
  if (match === null) {
    throw new TypeError(`Not a valid MVR amount: ${input}`);
  }
  const [, sign, rufiyaa, fraction = ''] = match;
  const laari = Number(rufiyaa) * 100 + Number(fraction.padEnd(2, '0'));
  if (!Number.isSafeInteger(laari)) {
    throw new TypeError(`MVR amount out of safe range: ${input}`);
  }
  return sign === '-' ? -laari : laari;
}
