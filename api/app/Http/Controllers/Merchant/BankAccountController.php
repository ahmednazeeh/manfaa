<?php

declare(strict_types=1);

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Http\Resources\MerchantBankAccountResource;
use App\Models\MerchantUser;
use Illuminate\Http\Request;

/**
 * PATCH /merchant/bank-account (owner only). The bank identity is one
 * atomic triple — a half-updated identity mismatches every payment — so
 * all three fields are required together. See MerchantBankAccountResource
 * for what this identity is used for (and what it is not).
 */
class BankAccountController extends Controller
{
    public function update(Request $request): MerchantBankAccountResource
    {
        $validated = $request->validate([
            'bank_name' => ['required', 'string', 'max:255'],
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
