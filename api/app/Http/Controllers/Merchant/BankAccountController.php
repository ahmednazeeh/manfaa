<?php

declare(strict_types=1);

namespace App\Http\Controllers\Merchant;

use App\Domain\Platform\Bank;
use App\Http\Controllers\Controller;
use App\Http\Resources\MerchantBankAccountResource;
use App\Models\MerchantUser;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The merchant's own bank identity: read on `bank_account.view`, written on
 * `bank_account.update`. The two are separate permissions because seeing
 * which account the store is matched against is bookkeeping, while
 * repointing it is the single most consequential change in the panel.
 *
 * The identity is one atomic triple — a half-updated identity mismatches
 * every payment — so all three fields are required together on the write.
 * See MerchantBankAccountResource for what this identity is used for (and
 * what it is not).
 */
class BankAccountController extends Controller
{
    public function show(Request $request): MerchantBankAccountResource
    {
        /** @var MerchantUser $user */
        $user = $request->user('merchant');

        return new MerchantBankAccountResource($user->merchant);
    }

    public function update(Request $request): MerchantBankAccountResource
    {
        $validated = $request->validate([
            'bank_name' => ['required', Rule::enum(Bank::class)],
            'bank_account' => ['required', 'string', 'max:64'],
            'bank_account_name' => ['required', 'string', 'max:255'],
        ]);

        /** @var MerchantUser $user */
        $user = $request->user('merchant');

        $merchant = $user->merchant;
        $merchant->fill($validated)->save();

        return new MerchantBankAccountResource($merchant->refresh());
    }
}
