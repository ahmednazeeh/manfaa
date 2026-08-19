<?php

declare(strict_types=1);

namespace App\Domain\Transfers;

use App\Models\TransferProfile;
use App\Models\TransferSetting;

/**
 * The profile a pass runs against, resolved ONCE at the door.
 *
 * Resolving per item would let an admin change the default halfway through a
 * batch and split it across two banks — every row would still be idempotent,
 * and the batch would still be wrong.
 */
final readonly class TransferProfileRef
{
    private function __construct(
        public TransferProfile $profile,
        /**
         * Active profiles keyed by the bank they debit, for keeping a payout
         * inside one bank where we hold an account at the payee's.
         *
         * @var array<string, TransferProfile>
         */
        private array $byBank = [],
    ) {}

    /**
     * The profile to pay one payee from.
     *
     * Where we hold an account at the payee's own bank, we pay from it: an
     * MIB-to-MIB transfer settles differently from one crossing banks, and
     * the row already records where the money is going. Where we do not,
     * this is the batch's profile and nothing changes.
     *
     * Falls back silently rather than refusing — a payee at a bank we have
     * no account with must still be paid.
     */
    public function forBank(?string $bank): TransferProfile
    {
        $bank = mb_strtolower(trim((string) $bank));

        return $this->byBank[$bank] ?? $this->profile;
    }

    public static function resolve(?int $profileId = null): self
    {
        if ($profileId !== null) {
            $chosen = TransferProfile::query()->where('active', true)->find($profileId);

            if ($chosen === null) {
                throw new BatchNotSendableException('That transfer profile is not active.');
            }

            // An explicitly chosen profile is a decision, not a default:
            // every row goes through it, bank or no bank.
            return new self($chosen);
        }

        $settings = TransferSetting::current();

        $profile = $settings->profile_id !== null
            ? TransferProfile::query()->where('active', true)->find($settings->profile_id)
            : TransferProfile::query()->where('active', true)->where('is_default', true)->first();

        if ($profile === null) {
            throw new BatchNotSendableException('No active transfer profile is configured.');
        }

        return new self($profile, self::byBank());
    }

    /**
     * @return array<string, TransferProfile>
     */
    private static function byBank(): array
    {
        $map = [];

        foreach (TransferProfile::query()->where('active', true)->orderByDesc('is_default')->orderBy('id')->get() as $profile) {
            $bank = $profile->bank();

            // First one wins, default first — so a bank with two profiles
            // resolves to the one an operator marked, not to whichever row
            // happened to be created first.
            if ($bank !== null && ! isset($map[$bank])) {
                $map[$bank] = $profile;
            }
        }

        return $map;
    }
}
