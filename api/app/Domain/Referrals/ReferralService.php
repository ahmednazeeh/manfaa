<?php

declare(strict_types=1);

namespace App\Domain\Referrals;

use App\Domain\Customers\MaskedName;
use App\Domain\Notifications\NotificationService;
use App\Domain\Notifications\NotificationTemplateKey;
use App\Domain\Platform\PlatformConfig;
use App\Domain\Wallet\WalletService;
use App\Models\Customer;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The customer referral programme (owner, 2026-08-23).
 *
 * A customer's 6-digit customer_code IS their referral code: a new customer
 * may type one at signup, and when that new customer's cumulative VALIDATED
 * spend crosses the configurable threshold, the REFERRER earns the
 * configurable bonus into their wallet — instantly, once per referred
 * customer, ever. No time limit, no clawback.
 *
 * "Validated spend" is eligible_laari summed over confirmed and paid —
 * spend a merchant has actually FUNDED (owner decision 2026-08-23,
 * tightened from payable_unfunded the same day): a platform-funded bonus
 * must never be minted by credits a defaulting merchant recorded and will
 * never settle. Pending, unfunded, reversed and written-off never count.
 *
 * ONCE-EVER is enforced twice, deliberately: `referral_rewarded_at` on the
 * REFERRED customer is checked and stamped under a row lock, and the wallet
 * credit itself is idempotent per (reference_type 'customer', reference_id =
 * referred id, type 'referral') — so even a stamp lost to a crash between
 * the two writes cannot pay twice.
 *
 * SELF-REFERRAL, NO TOLERANCE (owner, 2026-08-24): if referrer and referred
 * have EVER been seen on the same device (DeviceIdentity — hashed OS ids and
 * the web browser ref — or a shared FCM token in device_tokens), the referred
 * customer's bonus is DISQUALIFIED at award time: stamped
 * `referral_disqualified_at`, never paid, never retried, no review queue.
 * Permanent by construction — every award path skips the stamp the same way
 * it skips `referral_rewarded_at`. Already-paid bonuses are never clawed back
 * — which also means a collision whose evidence first lands AFTER the payout
 * is forgiven: sharesDevice() is consulted exactly once, at award time. The
 * defence's other honest limits are on the record in DeviceIdentity's
 * KNOWN-OPEN PATHS docblock — it deters casual self-referral; it cannot
 * convict devices the store never saw.
 *
 * OFF-LEDGER, deliberately: the customer wallet lives entirely outside the
 * §8 ledger (marketplace refunds credit it with no journal, and withdrawals
 * post nothing), and the Reconciler derives Customer Cashback Liability
 * from transaction states alone. A referral bonus therefore posts NO
 * journal — a CR to the cashback liability here would diverge the daily
 * reconciliation by the cumulative sum of every bonus ever paid, forever.
 * ReferralTest pins this with a Reconciler run over a paid bonus.
 */
final readonly class ReferralService
{
    public function __construct(
        private PlatformConfig $config,
        private WalletService $wallet,
        private NotificationService $notifications,
        private DeviceIdentity $devices,
    ) {}

    /**
     * Resolve a typed referral code to the ACTIVE customer it names, or
     * null. Null is always a silent "no attribution" — a signup must never
     * fail over the referral code (the typo is the typist's loss, not a
     * reason to refuse them an account).
     */
    public function resolveReferrer(?string $code): ?Customer
    {
        if ($code === null || preg_match('/^\d{6}$/', $code) !== 1) {
            return null;
        }

        return Customer::query()
            ->where('customer_code', $code)
            ->where('status', 'active')
            ->first();
    }

    /**
     * The transition hook's entry point: cheap enough to run on EVERY
     * payable/confirmed transition. One primary-key read answers "was this
     * customer referred, and still unrewarded" before any SUM runs — a
     * customer who was never referred costs exactly that one lookup.
     */
    public function checkCustomer(int $customerId): void
    {
        $referred = Customer::query()
            ->whereKey($customerId)
            ->whereNotNull('referred_by_customer_id')
            ->whereNull('referral_rewarded_at')
            ->whereNull('referral_disqualified_at')
            ->first();

        if ($referred !== null) {
            $this->award($referred);
        }
    }

    /**
     * Award the referrer's bonus if this referred customer's validated spend
     * has crossed the threshold. Safe to call repeatedly — from the
     * transition hook, the daily safety net, or both at once.
     *
     * @return bool true when the bonus was credited BY THIS CALL
     */
    public function award(Customer $referred): bool
    {
        // A zero reward is a pause with extra steps: nothing is credited and
        // — deliberately — nothing is stamped, so the referrer is still owed
        // when an admin sets a real figure again. No time limit means
        // exactly that.
        if (! $this->config->referralEnabled() || $this->config->referralRewardLaari() <= 0) {
            return false;
        }

        // The cheap column guard, mirrored inside the lock below: a
        // disqualified customer costs the caller nothing further, ever.
        if ($referred->referral_disqualified_at !== null) {
            return false;
        }

        if ($this->validatedSpendLaari((int) $referred->getKey()) < $this->config->referralSpendThresholdLaari()) {
            return false;
        }

        return DB::transaction(function () use ($referred): bool {
            // Re-read under a row lock: two transitions crossing the
            // threshold together serialise here, and the loser sees the
            // stamp the winner wrote.
            $locked = Customer::query()
                ->whereKey($referred->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->referral_rewarded_at !== null || $locked->referred_by_customer_id === null) {
                return false;
            }

            // Disqualified is PERMANENT: stamped once, skipped forever —
            // no re-run, threshold change or admin toggle revives it.
            if ($locked->referral_disqualified_at !== null) {
                return false;
            }

            $referrer = Customer::query()->find($locked->referred_by_customer_id);

            if ($referrer === null) {
                return false;
            }

            // SELF-REFERRAL, NO TOLERANCE (owner, 2026-08-24): a device
            // ever shared between referrer and referred — or a currently
            // shared FCM token — kills this bonus at the moment it would
            // have paid. Stamp, no credit, no push, and every later run
            // stops at the guard above. Already-rewarded customers never
            // reach this line, so nothing already paid is ever touched.
            if ($this->devices->sharesDevice($referrer, $locked)) {
                $locked->forceFill([
                    'referral_disqualified_at' => CarbonImmutable::now('UTC'),
                    'referral_disqualified_reason' => 'device_collision',
                ])->save();

                return false;
            }

            $reward = $this->config->referralRewardLaari();

            // MASKED, same idiom as the friends list ("Ais***"): the wallet
            // description and the push both reach the referrer, and the
            // privacy contract (ReferralsController) says a referrer never
            // learns more about a friend than the friend's signup gave away.
            $friend = MaskedName::of(self::firstName((string) $locked->name));

            // Idempotent per (customer, referred id, referral) — see
            // WalletService::credit(); null means it was already paid.
            $entry = $this->wallet->credit(
                $referrer,
                $reward,
                type: 'referral',
                referenceType: 'customer',
                referenceId: (int) $locked->getKey(),
                description: sprintf('Referral bonus — %s reached their milestone', $friend),
            );

            $locked->forceFill(['referral_rewarded_at' => CarbonImmutable::now('UTC')])->save();

            if ($entry === null) {
                // Paid on an earlier run whose stamp never landed (a crash
                // between the credit and the stamp): the stamp above repairs
                // the record, and nothing else may fire again.
                return false;
            }

            // Defers itself to after this transaction commits, and can
            // never fail the award — see NotificationService.
            $this->notifications->send(NotificationTemplateKey::ReferralBonusEarned, $referrer, [
                'amount' => NotificationService::money($reward),
                'friend' => $friend,
            ]);

            return true;
        });
    }

    /**
     * Cumulative validated spend: what survived the validation window,
     * regardless of whether the merchant has settled it.
     */
    public function validatedSpendLaari(int $customerId): int
    {
        return (int) Transaction::query()
            ->where('customer_id', $customerId)
            ->whereIn('state', ['confirmed', 'paid'])
            ->sum('eligible_laari');
    }

    private static function firstName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        return $parts[0] ?? '';
    }
}
