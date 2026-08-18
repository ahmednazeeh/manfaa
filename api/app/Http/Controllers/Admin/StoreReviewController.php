<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Money\Percent;
use App\Domain\Onboarding\OnboardingException;
use App\Domain\Onboarding\OnboardingService;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The superadmin store approval queue (§1 decision 2026-08-15): everything
 * a self-signed-up store entered in the wizard, reviewable per state.
 * Approve activates the store (it becomes publicly visible on the next
 * discovery read); reject sends it back with a required reason for the
 * merchant to fix and resubmit.
 */
class StoreReviewController extends Controller
{
    private const array STATES = ['pending_review', 'rejected', 'draft'];

    public function __construct(private readonly OnboardingService $onboarding) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'state' => ['sometimes', 'string', Rule::in(self::STATES)],
        ]);

        $state = $validated['state'] ?? 'pending_review';

        $counts = Merchant::query()
            ->whereIn('status', self::STATES)
            ->selectRaw('status, COUNT(*) AS n')
            ->groupBy('status')
            ->pluck('n', 'status');

        $merchants = Merchant::query()
            ->where('status', $state)
            ->when($state === 'pending_review',
                fn ($query) => $query->orderBy('submitted_at')->orderBy('id'),
                fn ($query) => $query->orderByDesc('updated_at')->orderByDesc('id'))
            ->limit(200)
            ->get();

        return response()->json([
            'data' => $merchants->map(fn (Merchant $merchant): array => [
                'id' => $merchant->id,
                'name' => $merchant->name,
                'slug' => $merchant->slug,
                'status' => $merchant->status,
                'category' => $merchant->category,
                'channel' => $merchant->channel,
                'eligibility_basis' => $merchant->eligibility_basis,
                'description' => $merchant->description,
                'description_dv' => $merchant->description_dv,
                'contact_email' => $merchant->contact_email,
                'contact_phone' => $merchant->contact_phone,
                'logo_url' => $this->onboarding->logoUrl($merchant),
                // The map pin is not an approval requirement (D17), which is
                // exactly why the reviewer has to be shown it: nothing else
                // on this row says whether the store can be found on a map.
                'primary_branch' => $this->onboarding->presentPrimaryBranch($merchant),
                // PLAN §1 wire format: 2-decimal percent string, null when
                // the store has not set a rate yet.
                'cashback_rate_percent' => Percent::formatOrNull($this->onboarding->currentRateBp($merchant)),
                'setup_state' => (object) ((array) ($merchant->setup_state ?? [])),
                'submitted_at' => $merchant->submitted_at?->toIso8601String(),
                'rejected_at' => $merchant->rejected_at?->toIso8601String(),
                'rejected_reason' => $merchant->rejected_reason,
                'created_at' => $merchant->created_at?->toIso8601String(),
            ])->values(),
            'meta' => [
                'state' => $state,
                'counts' => [
                    'pending_review' => (int) ($counts['pending_review'] ?? 0),
                    'rejected' => (int) ($counts['rejected'] ?? 0),
                    'draft' => (int) ($counts['draft'] ?? 0),
                ],
            ],
        ]);
    }

    public function approve(Request $request, Merchant $merchant): JsonResponse
    {
        try {
            $merchant = $this->onboarding->approve($merchant, $request->user('admin'));
        } catch (OnboardingException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->httpStatus);
        }

        return response()->json([
            'data' => [
                'id' => $merchant->id,
                'status' => $merchant->status,
                'approved_at' => $merchant->approved_at?->toIso8601String(),
                'approved_by' => $merchant->approved_by,
            ],
        ]);
    }

    public function reject(Request $request, Merchant $merchant): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        try {
            $merchant = $this->onboarding->reject($merchant, $validated['reason']);
        } catch (OnboardingException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->httpStatus);
        }

        return response()->json([
            'data' => [
                'id' => $merchant->id,
                'status' => $merchant->status,
                'rejected_at' => $merchant->rejected_at?->toIso8601String(),
                'rejected_reason' => $merchant->rejected_reason,
            ],
        ]);
    }
}
