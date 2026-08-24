import { Bot, Hand, Radio, SearchX } from 'lucide-react';
import { formatDateTime } from '@/lib/format';
import { matchRuleLabel } from '@/lib/labels';
import { Badge } from '@/components/ui/badge';
import type { AutoVerifyStatus } from './auto-verify';

/**
 * The verifier's verdict as a chip. Nothing renders for a rejected row —
 * the rejection is its own chip and its own reason — and nothing renders
 * while the transfer settings are still loading, because a wrong guess
 * ("watching") would send an operator away from a row that needs them.
 */
export function AutoVerifyBadge({ status }: { status: AutoVerifyStatus }) {
  switch (status.kind) {
    case 'auto_matched':
      return (
        <Badge variant="info" appearance="light" size="sm">
          <Bot className="size-3" />
          Matched automatically
        </Badge>
      );
    case 'matched_by_hand':
      return (
        <Badge variant="secondary" appearance="light" size="sm">
          <Hand className="size-3" />
          Matched by admin
        </Badge>
      );
    case 'watching':
      return (
        <Badge
          variant="info"
          appearance="light"
          size="sm"
          title={`The bank is polled until ${formatDateTime(status.until.toISOString())}`}
        >
          <Radio className="size-3" />
          Watching the bank
        </Badge>
      );
    case 'not_found':
      return (
        <Badge variant="warning" appearance="light" size="sm">
          <SearchX className="size-3" />
          Not found by the bank watch
        </Badge>
      );
    case 'unpolled':
      return (
        <Badge variant="secondary" appearance="light" size="sm">
          Never watched
        </Badge>
      );
    case 'off':
      return (
        <Badge variant="secondary" appearance="light" size="sm">
          Auto-verify off
        </Badge>
      );
    case 'unwatched':
      return (
        <Badge variant="secondary" appearance="light" size="sm">
          Account not watched
        </Badge>
      );
    case 'rejected':
    case 'unknown':
      return null;
  }
}

/**
 * The same verdict as a sentence, for the review panel — what the machine
 * did, or why it could not, so the operator knows what is left to check.
 */
export function autoVerifyExplanation(status: AutoVerifyStatus): string | null {
  switch (status.kind) {
    case 'auto_matched': {
      const rule = matchRuleLabel(status.rule);
      return `The bank-history verifier found this transfer${
        rule ? ` by the ${rule}` : ''
      }${
        status.score !== null ? ` (name score ${status.score})` : ''
      } and credited the wallet without anyone signing for it.`;
    }
    case 'matched_by_hand':
      return 'An admin confirmed this transfer by hand and the wallet was credited.';
    case 'watching':
      return `The verifier is still polling the bank for this transfer, until ${formatDateTime(
        status.until.toISOString(),
      )}. It matches on amount plus a bank-issued reference only — the one the merchant typed, or one read off the slip; a payer name is never enough for a top-up — and if it finds the credit first, this row leaves the queue on its own.`;
    case 'not_found':
      return 'The verifier polled the bank for the whole window and found no credit matching this claim. Check the slip against the statement before matching by hand.';
    case 'unpolled':
      return 'Nothing ever polled the bank for this claim — automatic verification was off when it was made — so a person has to check the slip against the statement.';
    case 'off':
      return 'Automatic verification is switched off, so every claim waits for a person. Turn it on in Settings › Transfer API.';
    case 'unwatched':
      return 'No profile reads the history of the account this claim names, so the verifier never looked. Verify the slip against the statement by hand.';
    case 'rejected':
    case 'unknown':
      return null;
  }
}
