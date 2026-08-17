<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use App\Models\Customer;
use App\Models\DeviceToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Account deletion is ANONYMISATION, not row deletion (store-readiness
 * decision 2026-08-17): transactions and payout rows are financial records
 * shared with the stores that funded the cashback, so the ledger survives
 * intact while everything that identifies the person is unlinked.
 *
 * The password randomisation doubles as the remote sign-out: every live
 * session's stored password-hash pair stops validating on its next request
 * (AuthenticateMultiGuardSession logs that guard out), and the personal
 * access tokens are revoked outright.
 */
final readonly class AccountDeletionService
{
    public function delete(Customer $customer): void
    {
        DB::transaction(function () use ($customer): void {
            $customer->tokens()->delete();

            DeviceToken::query()
                ->where('tokenable_type', $customer->getMorphClass())
                ->where('tokenable_id', $customer->id)
                ->delete();

            if ($customer->avatar_path !== null) {
                Storage::delete($customer->avatar_path);
            }

            $customer->forceFill([
                'name' => 'Deleted member',
                // The unique phone slot is tombstoned per-account, which
                // frees the real number to register a brand-new account.
                'phone' => 'del:'.$customer->id,
                'phone_verified_at' => null,
                'email' => null,
                'password' => bcrypt(Str::random(40)),
                'status' => 'closed',
                'payout_bank' => null,
                'payout_account' => null,
                'payout_account_name' => null,
                'avatar_path' => null,
                'remember_token' => null,
            ])->save();
        });
    }
}
