<?php

declare(strict_types=1);

namespace App\Domain\Transfers;

use App\Domain\MerchantAccess\Permission;
use App\Domain\Notifications\NotificationService;
use App\Domain\Notifications\NotificationTemplateKey;
use App\Jobs\MarkBankCreditUsed;
use App\Models\Order;
use App\Models\PlatformBankAccount;
use App\Models\TransferProfile;
use App\Models\TransferSetting;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Matching an incoming bank credit to an order (owner spec 2026-08-19).
 *
 * Two things must agree before money is accepted without a person looking:
 * the AMOUNT to the laari, and the PAYER'S NAME by {@see NameMatcher}. Either
 * alone is too weak — amounts repeat all day, and a name alone would accept
 * the wrong transfer from the right person.
 *
 * A row already claimed by another order is skipped. Two customers paying
 * the same amount within the same window is exactly the case that would
 * otherwise verify both from one transfer.
 */
final readonly class PaymentVerifier
{
    public function __construct(
        private BankHistoryClient $history,
        private NameMatcher $names,
        private NotificationService $notifications,
        private BankCreditClaim $claims,
    ) {}

    /**
     * Look once. Returns true when the order was verified.
     */
    public function attempt(Order $order): bool
    {
        $settings = TransferSetting::current();

        if (! $settings->auto_verify_enabled) {
            return false;
        }

        if ($order->payment_state !== 'proof_submitted') {
            return false;
        }

        // The account the CUSTOMER was told to pay into — not one fixed
        // account. They pick BML or MIB at checkout and we hold one at each;
        // reading the wrong bank's history could only ever produce a match
        // that means nothing.
        $destination = $this->destination($order);

        if ($destination === null) {
            return false;
        }

        [$profile, $account] = $destination;

        $customer = $order->customer;

        if ($customer === null || trim((string) $customer->name) === '') {
            // Nothing to match a name against. A person looks — which is the
            // ordinary path, not a failure.
            return false;
        }

        $rows = $this->history->history($profile, $account);

        foreach ($rows as $row) {
            if (! $row->incoming || $row->amountLaari !== $order->total_payable_laari) {
                continue;
            }

            // Already spent. This check is a COURTESY — it lets us move to
            // the next row instead of throwing — but the guarantee is the
            // unique index on matched_trx_id, because two workers reading the
            // same history at the same instant would both see it unclaimed.
            //
            // It asks about SETTLEMENTS too. This used to look only at
            // `orders`, so a credit already spent settling a merchant's bill
            // could still verify a customer's order — one MVR arriving, two
            // things marked paid. The unique indexes cannot catch that: they
            // are per-table.
            if ($this->claims->taken($row, exceptOrder: (int) $order->getKey())) {
                continue;
            }

            $score = $this->names->score((string) $customer->name, $row->name);

            if ($score === null || $score < $settings->verify_min_score) {
                continue;
            }

            if (! $this->verify($order, $row, $score)) {
                return false;
            }

            // Hide the credit from the shared BML feed. The order side reads
            // the same account as settlements, and IsleBooks reads it too —
            // see MarkBankCreditUsed. verify() has committed the match.
            MarkBankCreditUsed::dispatch((int) $profile->getKey(), $row->key());

            return true;
        }

        return false;
    }

    private function verify(Order $order, BankRow $row, int $score): bool
    {
        try {
            $this->write($order, $row, $score);
        } catch (UniqueConstraintViolationException) {
            // Another order claimed this reference first. A bank reference
            // pays for one order and one only — losing here is the index
            // doing its job, not an error.
            Log::info('Bank reference already claimed', [
                'order' => $order->reference,
                'trx' => $row->key(),
            ]);

            return false;
        }

        $fresh = $order->fresh();

        if ($fresh?->payment_state !== 'verified') {
            return false;
        }

        Log::info('Order payment auto-verified', [
            'order' => $order->reference,
            'trx' => $row->key(),
            'score' => $score,
        ]);

        // The money is real, so the shops can start work. Told HERE rather
        // than at placement: an unpaid order is not something a shop should
        // be interrupted for (owner requirement 2026-08-19).
        $this->tellShops($fresh);

        return true;
    }

    /**
     * Which of our accounts this order was paid into, and how to read it.
     *
     * Returns null — meaning "a person looks" — when the account has no
     * profile mapped. There is deliberately NO fallback to a default
     * profile: a fallback here would poll some other bank entirely.
     *
     * @return array{TransferProfile, string}|null
     */
    private function destination(Order $order): ?array
    {
        $bank = trim((string) $order->payment_method);

        if ($bank === '') {
            return null;
        }

        $platform = PlatformBankAccount::query()
            ->where('active', true)
            ->whereRaw('lower(bank_name) = ?', [mb_strtolower($bank)])
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->first();

        if ($platform === null || $platform->verify_profile_id === null) {
            return null;
        }

        $profile = TransferProfile::query()->where('active', true)->find($platform->verify_profile_id);
        $account = trim((string) $platform->account_no);

        if ($profile === null || $account === '') {
            return null;
        }

        return [$profile, $account];
    }

    /** Every shop in the order learns it has been paid for. */
    private function tellShops(Order $order): void
    {
        foreach ($order->suborders()->with('merchant')->get() as $suborder) {
            $merchant = $suborder->merchant;

            if ($merchant === null) {
                continue;
            }

            $this->notifications->sendToMerchantStaff(
                NotificationTemplateKey::OrderPlaced,
                $merchant,
                [
                    'reference' => (string) $suborder->reference,
                    'amount' => 'MVR '.number_format($suborder->subtotal_laari / 100, 2),
                ],
                Permission::MarketplaceManage,
            );
        }
    }

    private function write(Order $order, BankRow $row, int $score): void
    {
        DB::transaction(function () use ($order, $row, $score): void {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            // Re-checked under the lock: a human may have verified it while
            // we were reading the bank, and verifying twice would restate a
            // decision somebody already made.
            if ($locked->payment_state !== 'proof_submitted') {
                return;
            }

            // Cross-table: serialise on the credit itself, then ask again —
            // a settlement payment or wallet top-up may have spent it since.
            $this->claims->lock($row);

            if ($this->claims->taken($row, exceptOrder: (int) $locked->getKey())) {
                return;
            }

            $locked->forceFill([
                'payment_state' => 'verified',
                'verified_at' => CarbonImmutable::now(),
                'state' => 'under_review',
                'auto_verified' => true,
                // The merchant-facing reference, not the internal statement id.
                'matched_trx_id' => $row->key(),
                // Every name this credit answers to. See BankCreditClaim.
                'matched_trx_refs' => $row->identifiers(),
                // Kept whether or not it verified: an operator reading this
                // later needs to see what the bank actually said, not our
                // conclusion about it.
                'matched_payer_name' => $row->name,
                'matched_score' => $score,
                // Deliberately NOT verified_by — no admin decided this, and
                // filing one would put a person's name on a machine's call.
                'poll_until' => null,
            ])->save();
        });
    }
}
