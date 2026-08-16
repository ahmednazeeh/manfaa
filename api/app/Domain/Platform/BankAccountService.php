<?php

declare(strict_types=1);

namespace App\Domain\Platform;

use App\Models\AdminUser;
use App\Models\PlatformBankAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CRUD over the platform's own bank accounts — where merchants send
 * settlement transfers. At most one row is the active primary (partial
 * unique index backs it at the database); promoting a row demotes the
 * current primary in the same transaction. The active primary's details are
 * cached briefly for the settlement-instructions embed and busted on every
 * write.
 *
 * Money-bearing configuration (§13): every write stamps the acting admin
 * (created_by / updated_by) and the account number is immutable once
 * created — replacement is create-new + deactivate-old, never an in-place
 * rewrite of where merchants were told to send settlements.
 */
final class BankAccountService
{
    private const string PRIMARY_CACHE_KEY = 'platform_bank_accounts.active_primary';

    private const string ACTIVE_CACHE_KEY = 'platform_bank_accounts.active_all';

    private const int CACHE_TTL_SECONDS = 60;

    /**
     * @param  array{bank_name: string, account_no: string, account_name: string, currency?: string, is_primary?: bool, active?: bool}  $attributes
     */
    public function create(array $attributes, ?AdminUser $actor = null): PlatformBankAccount
    {
        return DB::transaction(function () use ($attributes, $actor): PlatformBankAccount {
            $account = new PlatformBankAccount([
                'currency' => 'MVR',
                'is_primary' => false,
                'active' => true,
                ...$attributes,
            ]);

            $account->created_by = $actor?->id;
            $account->updated_by = $actor?->id;

            if ($account->is_primary && $account->active) {
                $this->demoteCurrentPrimary();
            }

            $account->save();
            $this->bustCache();

            return $account;
        });
    }

    /**
     * The account NUMBER is immutable once created — merchants were told to
     * pay it, so an in-place rewrite would leave old settlement instructions
     * inexplicable (the reason there is no DELETE either). Replacing an
     * account is create-new + deactivate-old, keeping both rows explicable.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws BankAccountException when the update tries to change account_no
     */
    public function update(PlatformBankAccount $account, array $attributes, ?AdminUser $actor = null): PlatformBankAccount
    {
        return DB::transaction(function () use ($account, $attributes, $actor): PlatformBankAccount {
            PlatformBankAccount::query()->whereKey($account->getKey())->lockForUpdate()->first();
            $account->refresh()->fill($attributes);

            if ($account->isDirty('account_no')) {
                throw BankAccountException::immutableAccountNo();
            }

            $account->updated_by = $actor?->id;

            if ($account->is_primary && $account->active && ($account->isDirty('is_primary') || $account->isDirty('active'))) {
                $this->demoteCurrentPrimary($account->id);
            }

            $account->save();
            $this->bustCache();

            return $account;
        });
    }

    /**
     * The active primary account's transfer details for the merchant
     * settlement instructions — null when none is configured (the embed then
     * carries needs_configuration; details are never invented).
     *
     * @return array{bank_name: string, account_no: string, account_name: string, currency: string}|null
     */
    public function activePrimaryDetails(): ?array
    {
        return Cache::remember(self::PRIMARY_CACHE_KEY, self::CACHE_TTL_SECONDS, function (): ?array {
            // Deploy-order safety: code can reach production before the
            // platform_bank_accounts migration runs. hasTable, not a
            // QueryException catch — a failed query would abort any
            // surrounding DB transaction.
            if (! Schema::hasTable('platform_bank_accounts')) {
                return null;
            }

            $account = PlatformBankAccount::query()
                ->where('active', true)
                ->where('is_primary', true)
                ->first();

            if ($account === null) {
                return null;
            }

            return [
                'bank_name' => $account->bank_name,
                'account_no' => $account->account_no,
                'account_name' => $account->account_name,
                'currency' => $account->currency,
            ];
        });
    }

    /**
     * Every account a merchant may transfer to — at most one per bank, since
     * that is what the partial unique index allows — with the primary first
     * so a panel that preselects the head of the list preselects the default.
     *
     * The merchant CHOOSES from this rather than being told the primary:
     * someone banking with MIB pays nothing and waits nothing to send to MIB,
     * and a cross-bank transfer they did not have to make is a fee and a day
     * the platform imposed on them for its own filing convenience.
     *
     * @return list<array{id: int, bank_name: string, account_no: string, account_name: string, currency: string, is_primary: bool}>
     */
    public function activeAccounts(): array
    {
        return Cache::remember(self::ACTIVE_CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            // Same deploy-order safety as activePrimaryDetails().
            if (! Schema::hasTable('platform_bank_accounts')) {
                return [];
            }

            return PlatformBankAccount::query()
                ->where('active', true)
                ->orderByDesc('is_primary')
                ->orderBy('bank_name')
                ->get()
                ->map(fn (PlatformBankAccount $account): array => [
                    'id' => $account->id,
                    'bank_name' => $account->bank_name,
                    'account_no' => $account->account_no,
                    'account_name' => $account->account_name,
                    'currency' => $account->currency,
                    'is_primary' => (bool) $account->is_primary,
                ])
                ->values()
                ->all();
        });
    }

    private function demoteCurrentPrimary(?int $exceptId = null): void
    {
        PlatformBankAccount::query()
            ->where('is_primary', true)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->lockForUpdate()
            ->get()
            ->each(fn (PlatformBankAccount $current) => $current->forceFill(['is_primary' => false])->save());
    }

    private function bustCache(): void
    {
        Cache::forget(self::PRIMARY_CACHE_KEY);
        Cache::forget(self::ACTIVE_CACHE_KEY);
    }
}
