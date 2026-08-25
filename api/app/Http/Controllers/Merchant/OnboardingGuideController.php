<?php

declare(strict_types=1);

namespace App\Http\Controllers\Merchant;

use App\Domain\Onboarding\OnboardingGuide;
use App\Http\Controllers\Controller;
use App\Models\MerchantUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in person's own guided-setup state (owner, 2026-08-25): the
 * sidebar tasklist, the tour prompt, and the two ways to put them away.
 *
 * There is NO id in any of these routes and no permission gate on them.
 * Both facts are the authority model, not an omission: this is one
 * person's own onboarding, so the only account any of these three can
 * read or write is `$request->user('merchant')` — a cashier cannot skip an
 * owner's tasklist because there is no shape of request that names another
 * account. And gating it on a permission would hide the tasklist from the
 * very staff who most need telling how the till works.
 *
 * Everything answers the SAME payload — the full state after the change —
 * so a client never has to follow a write with a read.
 */
class OnboardingGuideController extends Controller
{
    public function __construct(private readonly OnboardingGuide $guide) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->guide->state($this->user($request))]);
    }

    /**
     * Permanent and immediate, exactly as the owner asked: nothing
     * un-skips, and a second call is the same as the first.
     */
    public function skip(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->guide->skip($this->user($request))]);
    }

    /**
     * The walkthrough was finished, so stop offering it. The tasklist
     * itself stays until it is skipped or the five days run out — watching
     * the tour is not the same as having credited anybody.
     */
    public function completeTour(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->guide->completeTour($this->user($request))]);
    }

    private function user(Request $request): MerchantUser
    {
        /** @var MerchantUser $user */
        $user = $request->user('merchant');

        return $user;
    }
}
