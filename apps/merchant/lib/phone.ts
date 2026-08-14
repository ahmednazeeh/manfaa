/**
 * Maldivian mobile normalisation, mirroring the customer app: accepts
 * "7XXXXXX", "9XXXXXX", "960 7XXXXXX" or "+9607XXXXXX" (spaces/dashes
 * ignored) and returns the canonical `+960[79]XXXXXX` the API expects, or
 * null when the input is not a Maldivian mobile number.
 */
export function normalizeMaldivesPhone(input: string): string | null {
  const digits = input.replace(/[\s-]/g, '');
  const match = /^(?:\+?960)?([79]\d{6})$/.exec(digits);
  return match === null ? null : `+960${match[1]}`;
}
