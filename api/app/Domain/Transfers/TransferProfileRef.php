<?php

declare(strict_types=1);

namespace App\Domain\Transfers;

use App\Models\TransferProfile;
use App\Models\TransferSetting;

/**
 * The profile a pass runs against, resolved ONCE at the door.
 *
 * Resolving per item would let an admin change the default halfway through a
 * batch and split it across two upstreams — every row would still be
 * idempotent, and the batch would still be wrong.
 *
 * ONE profile pays everybody. We do not route by the payee's bank: every
 * payout leaves from MIB whatever the payee banks with, and BML is never
 * sent from at all (owner 2026-08-19).
 */
final readonly class TransferProfileRef
{
    private function __construct(public TransferProfile $profile) {}

    public static function resolve(?int $profileId = null): self
    {
        if ($profileId !== null) {
            $chosen = TransferProfile::query()->where('active', true)->find($profileId);

            if ($chosen === null) {
                throw new BatchNotSendableException('That transfer profile is not active.');
            }

            if (! $chosen->canSend()) {
                throw new BatchNotSendableException(
                    $chosen->name.' can only be read, not sent from.'
                );
            }

            // An explicitly chosen profile is a decision, not a default:
            // every row goes through it, bank or no bank.
            return new self($chosen);
        }

        $settings = TransferSetting::current();

        $profile = $settings->profile_id !== null
            ? TransferProfile::query()->where('active', true)->find($settings->profile_id)
            : TransferProfile::query()->where('active', true)->where('is_default', true)->first();

        if ($profile === null || ! $profile->canSend()) {
            throw new BatchNotSendableException('No active transfer profile is configured.');
        }

        return new self($profile);
    }
}
