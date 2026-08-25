<?php

declare(strict_types=1);

namespace App\Http\Controllers\Merchant;

use App\Domain\MerchantAccess\Permission;
use App\Domain\Platform\PlatformConfig;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureMerchantPermission;
use App\Http\Resources\MerchantPreferencesResource;
use App\Models\MerchantUser;
use App\Rules\ValidationWindowDays;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * PATCH /merchant/preferences (owner only). Bounds are platform policy:
 * min_eligible_laari 0–100000 (MVR 0–1,000); validation_window_days is
 * capped at the ADMIN-governed platform window (PlatformConfig
 * default_validation_window_days, default 3) — never a merchant-picked 30.
 * The window defines what counts as BACKDATED (PLAN §1: CreditRecorder
 * routes any credit older than window + 3 days straight to payable_unfunded,
 * final and merchant-irreversible, instead of into review) and it defers the
 * merchant's own settlement clock start — a knob with those two effects must
 * not be raisable by the party it polices. Merchants may
 * tighten it freely; raising the ceiling is the admin's platform-settings
 * call. Both knobs apply to FUTURE credits only — rate/terms freeze onto
 * each transaction at occurred_at (§4), so history never moves.
 */
class PreferencesController extends Controller
{
    public function update(Request $request, PlatformConfig $config): MerchantPreferencesResource|JsonResponse
    {
        $validated = $request->validate([
            'settlement_method' => ['sometimes', 'string', Rule::in(['bank', 'wallet'])],
            // The wallet screen's toggle (owner, 2026-08-24): may the hourly
            // run settle validated cashback from the wallet balance? This
            // PATCH is its ONE write path — the wallet payload only reads it.
            'auto_settle_from_wallet' => ['sometimes', 'boolean'],
            'min_eligible_laari' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            // The SAME rule object both signup doors use (App\Rules\
            // ValidationWindowDays): one ceiling, read from platform
            // settings at request time, so a window a store was allowed to
            // pick at signup is never one this screen turns round and
            // refuses.
            'validation_window_days' => ['sometimes', new ValidationWindowDays($config)],
        ]);

        /** @var MerchantUser $user */
        $user = $request->user('merchant');

        // The wallet toggle authorises the platform to SPEND the balance
        // every hour. That is a Money decision, not an Account one: whoever
        // flips it must hold the permission to spend the wallet by hand.
        if (array_key_exists('auto_settle_from_wallet', $validated) && ! $user->can(Permission::WalletSettle)) {
            return EnsureMerchantPermission::deny(Permission::WalletSettle);
        }

        $merchant = $user->merchant;
        $merchant->fill($validated)->save();

        return new MerchantPreferencesResource($merchant->refresh());
    }
}
