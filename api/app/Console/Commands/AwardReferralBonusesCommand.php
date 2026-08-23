<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Platform\PlatformConfig;
use App\Domain\Referrals\ReferralService;
use App\Models\Customer;
use Illuminate\Console\Command;

/**
 * The referral safety net (owner, 2026-08-23). The transition hook awards
 * instantly; this daily sweep re-runs the same check over every referred,
 * still-unrewarded customer, and exists for the cases the hook cannot see:
 * an admin LOWERING the threshold (customers already past the new bar have
 * no further transition to fire on), and any hook a crash swallowed.
 *
 * Idempotent by construction — ReferralService::award() pays once per
 * referred customer ever, however many times anything asks.
 */
final class AwardReferralBonusesCommand extends Command
{
    protected $signature = 'manfaa:award-referral-bonuses';

    protected $description = 'Re-run the referral award check for every referred customer not yet rewarded';

    public function handle(ReferralService $referrals, PlatformConfig $config): int
    {
        if (! $config->referralEnabled()) {
            $this->info('Referral programme is off; nothing to award.');

            return self::SUCCESS;
        }

        $checked = 0;
        $awarded = 0;

        Customer::query()
            ->whereNotNull('referred_by_customer_id')
            ->whereNull('referral_rewarded_at')
            ->orderBy('id')
            ->chunkById(200, function ($customers) use ($referrals, &$checked, &$awarded): void {
                foreach ($customers as $customer) {
                    $checked++;
                    $awarded += $referrals->award($customer) ? 1 : 0;
                }
            });

        $this->info(sprintf('%d referred customers checked, %d bonuses awarded.', $checked, $awarded));

        return self::SUCCESS;
    }
}
