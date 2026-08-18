/**
 * The ONE word count the panels police the store description with — the
 * client mirror of the API's App\Rules\MaxWords, exactly as percent.ts
 * mirrors App\Domain\Money\Percent.
 *
 * The ceiling is WORDS, not characters (owner decision 2026-08-18): a
 * character cap refuses a legitimately short description written in a
 * language whose words are long — Dhivehi above all — while waving through
 * 180 words of very short ones.
 *
 * The server is authoritative; this exists so a counter can turn red BEFORE
 * a save is refused, which only helps if the two agree on what a word is.
 * So the split mirrors PHP's
 * `preg_split('/\s+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY)`: any run of
 * whitespace separates words, and the empty pieces a leading or trailing run
 * would produce are dropped rather than counted.
 *
 * The two classes were compared code point by code point and agree on every
 * separator a person can type — space, tab, newline, the Unicode spaces,
 * NBSP, the ideographic space — with two exceptions neither side can
 * realistically meet in a shop's description: U+0085 (NEL) separates words
 * for PCRE and not for JavaScript, and U+FEFF (a stray byte-order mark) the
 * other way round. Each would move the count by one at most.
 */

/** PLAN §MR9 / the description column's ceiling, in words. */
export const STORE_DESCRIPTION_MAX_WORDS = 180;

/**
 * Any run of whitespace, in every script we serve. No `u` flag: both panels
 * compile against an ES5 target, where the flag is a syntax error — and it
 * buys nothing here, since JavaScript's `\s` covers the whole Unicode space
 * set (NBSP, the U+2000 block, U+3000) with or without it.
 */
const WORD_SEPARATOR = /\s+/;

/** How many words the API would count in this text. Empty text is 0. */
export function countWords(value: string): number {
  return value.split(WORD_SEPARATOR).filter((word) => word !== '').length;
}

/** True once the text is past the ceiling — i.e. once the API would refuse it. */
export function isOverWordCeiling(value: string, max: number): boolean {
  return countWords(value) > max;
}
