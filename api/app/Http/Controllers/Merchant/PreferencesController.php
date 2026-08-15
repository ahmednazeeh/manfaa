<?php

declare(strict_types=1);

namespace App\Http\Controllers\Merchant;

use App\Domain\Platform\PlatformConfig;
use App\Http\Controllers\Controller;
use App\Http\Resources\MerchantPreferencesResource;
use App\Models\MerchantUser;
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
    public function update(Request $request, PlatformConfig $config): MerchantPreferencesResource
    {
        $validated = $request->validate([
            'settlement_method' => ['sometimes', 'string', Rule::in(['bank', 'wallet'])],
            'min_eligible_laari' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'validation_window_days' => ['sometimes', 'integer', 'min:0', 'max:'.$config->defaultValidationWindowDays()],
        ]);

        /** @var MerchantUser $user */
        $user = $request->user('merchant');

        $merchant = $user->merchant;
        $merchant->fill($validated)->save();

        return new MerchantPreferencesResource($merchant->refresh());
    }
}
