<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Cashback\HoldNotReversibleException;
use App\Domain\Cashback\HoldReviewService;
use App\Domain\Cashback\NotOnHoldException;
use App\Http\Controllers\Controller;
use App\Http\Resources\HoldOutcomeResource;
use App\Http\Resources\HoldResource;
use App\Models\AdminUser;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The admin hold-review queue over HTTP (PLAN §13b task #22). Three routes,
 * all behind auth:admin — a hold is a fraud/dispute review, so no merchant,
 * vendor token or customer session can read it, let alone decide it.
 *
 * Both decisions require words. The release note is optional (a review that
 * simply cleared needs no essay) but is recorded verbatim when given; the
 * reject reason is required, because refusing a sale cancels a customer's
 * cashback and reverses a merchant's accrual, and "why" must survive on the
 * transaction's own append-only history rather than in somebody's memory.
 */
final class HoldsController extends Controller
{
    private const int MAX_PER_PAGE = 100;

    public function __construct(private readonly HoldReviewService $holds) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:64'],
            'merchant_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);

        // "?reason=" is no filter at all, not a filter for the empty string —
        // a UI that clears its picker must not silently empty the queue.
        $reason = trim((string) ($validated['reason'] ?? ''));

        $holds = $this->holds->list(
            $reason === '' ? null : $reason,
            isset($validated['merchant_id']) ? (int) $validated['merchant_id'] : null,
            (int) ($validated['per_page'] ?? 25),
        );

        // `summary` counts EVERY hold, not the filtered page: it feeds the nav
        // badge and the filter pickers, both of which must stay truthful while
        // the admin is looking at one store or one reason.
        return HoldResource::collection($holds)
            ->additional(['summary' => $this->holds->summary()])
            ->response();
    }

    public function release(Request $request, Transaction $transaction): JsonResponse
    {
        $validated = $request->validate([
            'note' => ['sometimes', 'nullable', 'string', 'min:3', 'max:1000'],
        ]);

        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        try {
            $released = $this->holds->release($transaction, $admin, $validated['note'] ?? null);
        } catch (NotOnHoldException $e) {
            return $this->refusal($e->getMessage(), NotOnHoldException::ERROR_CODE);
        }

        return new JsonResponse(['data' => (new HoldOutcomeResource($released))->resolve($request)]);
    }

    public function reject(Request $request, Transaction $transaction): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        try {
            $rejected = $this->holds->reject($transaction, $admin, $validated['reason']);
        } catch (NotOnHoldException $e) {
            return $this->refusal($e->getMessage(), NotOnHoldException::ERROR_CODE);
        } catch (HoldNotReversibleException $e) {
            return $this->refusal($e->getMessage(), $e->errorCode());
        }

        return new JsonResponse(['data' => (new HoldOutcomeResource($rejected))->resolve($request)]);
    }

    /**
     * 409 with a machine-readable code, the shape every other refusal on this
     * API uses, so the panel can branch on the code and show the sentence.
     */
    private function refusal(string $message, string $code): JsonResponse
    {
        return new JsonResponse(['message' => $message, 'code' => $code], 409);
    }
}
