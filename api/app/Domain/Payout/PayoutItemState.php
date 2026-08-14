<?php

namespace App\Domain\Payout;

/**
 * pending — created with the draft batch, nothing sent anywhere.
 * sent    — included in an exported bank file, awaiting the bank's result.
 * paid    — the bank confirmed the transfer; the item's transactions are paid.
 * failed  — the bank rejected the transfer; the item's transactions were
 *           unlinked and re-enter the next batch's eligibility.
 */
enum PayoutItemState: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Paid = 'paid';
    case Failed = 'failed';
}
