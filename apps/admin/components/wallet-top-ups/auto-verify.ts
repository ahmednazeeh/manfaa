import type { TransferSettingsResponse, WalletTopUp } from '@manfaa/api-client';

type TransferSettings = TransferSettingsResponse['data'];

/**
 * Where a top-up claim stands with the bank-history verifier — the thing an
 * operator most wants to know before opening a slip: has the machine looked,
 * is it still looking, or is this row waiting on a person because nobody
 * ever will?
 *
 * The row carries the OUTCOME (`auto_matched`, the `matched_*` columns)
 * and the watch AS IT STANDS (`poll_until`, `poll_attempts`) — the verdict
 * is read off those, never reconstructed from created_at plus today's
 * settings: a window changed after the claim, or a claim made while
 * auto-verify was off (nothing was ever dispatched), would otherwise read
 * "watching" with nobody watching.
 */
export type AutoVerifyStatus =
  | { kind: 'auto_matched'; rule: string | null; score: number | null }
  | { kind: 'matched_by_hand' }
  | { kind: 'rejected' }
  /** Automatic verification is switched off platform-wide. */
  | { kind: 'off' }
  /** The named account has no profile reading its history (or none was named). */
  | { kind: 'unwatched' }
  /** Inside the window: the poll is still asking the bank. */
  | { kind: 'watching'; until: Date }
  /** The window closed without a match — a person has to look. */
  | { kind: 'not_found' }
  /** Nothing ever polled this row (auto-verify was off at claim time). */
  | { kind: 'unpolled' }
  /** Transfer settings have not loaded yet. */
  | { kind: 'unknown' };

export function autoVerifyStatus(
  topUp: WalletTopUp,
  settings: TransferSettings | undefined,
  now: Date,
): AutoVerifyStatus {
  if (topUp.state === 'matched') {
    return topUp.auto_matched
      ? {
          kind: 'auto_matched',
          rule: topUp.matched_by_rule,
          score: topUp.matched_score,
        }
      : { kind: 'matched_by_hand' };
  }

  if (topUp.state === 'rejected') {
    return { kind: 'rejected' };
  }

  if (settings === undefined) {
    return { kind: 'unknown' };
  }

  if (!settings.auto_verify_enabled) {
    return { kind: 'off' };
  }

  const account =
    topUp.platform_bank_account_id === null
      ? undefined
      : settings.watched_accounts.find(
          (candidate) => candidate.id === topUp.platform_bank_account_id,
        );

  if (account === undefined || account.verify_profile_id === null) {
    return { kind: 'unwatched' };
  }

  // The row's own clock. poll_until is cleared on decision and null only
  // before the claim's watch was stamped, which never outlives the request.
  const until = topUp.poll_until === null ? null : new Date(topUp.poll_until);

  if (until === null || until <= now) {
    return topUp.poll_attempts > 0 ? { kind: 'not_found' } : { kind: 'unpolled' };
  }

  // A job that was dispatched looks within seconds; a row still at zero
  // attempts well after its claim was never given one.
  const claimedAt = new Date(topUp.created_at).getTime();
  const dispatched =
    topUp.poll_attempts > 0 || now.getTime() - claimedAt < 2 * 60_000;

  return dispatched ? { kind: 'watching', until } : { kind: 'unpolled' };
}
