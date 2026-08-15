<?php

namespace App\Domain\Payout;

/**
 * The §6 payout batch lifecycle, mapped onto the build → approve → export →
 * import flow:
 *
 * draft            — built; items and links exist; rebuild is cancel + recreate.
 * approved         — two distinct admins approved; the bank file may be exported.
 * processing       — the bank file was exported (exported_at set); items are sent.
 * sent             — a result file import has started; transient within the
 *                    import transaction unless the file only covered some items.
 * completed        — every item paid.
 * partially_failed — at least one item failed; its transactions were unlinked
 *                    and roll into the next batch.
 * cancelled        — a draft withdrawn before approval; its transaction links
 *                    were released. Terminal, kept for the audit trail.
 */
enum PayoutBatchState: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Processing = 'processing';
    case Sent = 'sent';
    case Completed = 'completed';
    case PartiallyFailed = 'partially_failed';
    case Cancelled = 'cancelled';

    /**
     * The state in words, for a sentence a person reads (PLAN §13b task #22 —
     * no raw snake_case in rendered output). The payout screens echo a
     * refusal message verbatim, so `partially_failed` must not be what an
     * admin sees when a batch refuses an action.
     *
     * Plain phrases, no article: callers compose them into their own
     * sentences ("... is partially failed", "only a draft batch can").
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'draft',
            self::Approved => 'approved',
            self::Processing => 'processing',
            self::Sent => 'sent',
            self::Completed => 'completed',
            self::PartiallyFailed => 'partially failed',
            self::Cancelled => 'cancelled',
        };
    }
}
