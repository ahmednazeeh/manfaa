<?php

namespace App\Http\Resources;

use App\Domain\Platform\PlatformConfig;
use App\Models\Merchant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Owner-editable operational preferences: how settlements are funded
 * (§7 — wallet is a funding method, not pre-funding) and the two
 * per-merchant earning knobs (minimum eligible amount, validation window)
 * that new transactions read at credit time.
 *
 * @mixin Merchant
 */
class MerchantPreferencesResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'settlement_method' => $this->settlement_method,
            // The hourly run may settle validated cashback from the wallet
            // balance, oldest first (owner, 2026-08-24). Read here and on
            // the wallet payload; written ONLY through this endpoint.
            'auto_settle_from_wallet' => (bool) $this->auto_settle_from_wallet,
            'min_eligible_laari' => (int) $this->min_eligible_laari,
            'validation_window_days' => (int) $this->validation_window_days,
            // The admin-governed ceiling the PATCH validates against (§11:
            // the stale-review window is not merchant-raisable) — exposed
            // so the panel form can render the real bound.
            'validation_window_max_days' => app(PlatformConfig::class)->defaultValidationWindowDays(),
        ];
    }
}
