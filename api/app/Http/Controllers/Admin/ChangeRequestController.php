<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Approvals\ChangeKind;
use App\Domain\Approvals\ChangeRequestException;
use App\Domain\Approvals\ChangeRequestService;
use App\Http\Controllers\Controller;
use App\Http\Resources\MerchantChangeRequestResource;
use App\Models\MerchantChangeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The store-CHANGE review queue (MR9) — the sibling of StoreReviewController,
 * which reviews stores that have never been live. Same split of authority:
 * any admin may READ the queue, only a SUPERADMIN decides, and a refusal
 * carries a required reason back to the merchant.
 *
 * Every row publishes its own before/after (MerchantChangeRequestResource),
 * built from the snapshot taken when the merchant submitted rather than from
 * the live store — so the diff a reviewer approves is the diff the merchant
 * proposed, however long the row waited.
 */
class ChangeRequestController extends Controller
{
    private const array STATUSES = [
        MerchantChangeRequest::PENDING,
        MerchantChangeRequest::APPROVED,
        MerchantChangeRequest::REJECTED,
        MerchantChangeRequest::SUPERSEDED,
    ];

    public function __construct(private readonly ChangeRequestService $changes) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(self::STATUSES)],
            'kind' => ['sometimes', 'string', Rule::in(ChangeKind::values())],
            'merchant_id' => ['sometimes', 'integer'],
        ]);

        $status = $validated['status'] ?? MerchantChangeRequest::PENDING;

        $counts = MerchantChangeRequest::query()
            ->selectRaw('status, COUNT(*) AS n')
            ->groupBy('status')
            ->pluck('n', 'status');

        $rows = MerchantChangeRequest::query()
            ->with(['merchant', 'submitter'])
            ->where('status', $status)
            ->when(isset($validated['kind']), fn ($query) => $query->where('kind', $validated['kind']))
            ->when(isset($validated['merchant_id']), fn ($query) => $query->where('merchant_id', $validated['merchant_id']))
            // Oldest first while pending — a queue is a queue; newest first
            // once decided, which is how a log is read.
            ->when($status === MerchantChangeRequest::PENDING,
                fn ($query) => $query->orderBy('created_at')->orderBy('id'),
                fn ($query) => $query->orderByDesc('reviewed_at')->orderByDesc('id'))
            ->limit(200)
            ->get();

        return response()->json([
            'data' => MerchantChangeRequestResource::collection($rows)->resolve($request),
            'meta' => [
                'status' => $status,
                'counts' => [
                    'pending' => (int) ($counts[MerchantChangeRequest::PENDING] ?? 0),
                    'approved' => (int) ($counts[MerchantChangeRequest::APPROVED] ?? 0),
                    'rejected' => (int) ($counts[MerchantChangeRequest::REJECTED] ?? 0),
                    'superseded' => (int) ($counts[MerchantChangeRequest::SUPERSEDED] ?? 0),
                ],
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $changeRequest = MerchantChangeRequest::query()->with(['merchant', 'submitter'])->findOrFail($id);

        return response()->json([
            'data' => (new MerchantChangeRequestResource($changeRequest))->resolve($request),
        ]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        return $this->decide($request, $id, fn (MerchantChangeRequest $changeRequest) => $this->changes->approve(
            $changeRequest, $request->user('admin'),
        ));
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        return $this->decide($request, $id, fn (MerchantChangeRequest $changeRequest) => $this->changes->reject(
            $changeRequest, $request->user('admin'), $validated['reason'],
        ));
    }

    /**
     * @param  callable(MerchantChangeRequest): MerchantChangeRequest  $decision
     */
    private function decide(Request $request, int $id, callable $decision): JsonResponse
    {
        $changeRequest = MerchantChangeRequest::query()->findOrFail($id);

        try {
            $decided = $decision($changeRequest);
        } catch (ChangeRequestException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->httpStatus);
        }

        return response()->json([
            'data' => (new MerchantChangeRequestResource($decided->load(['merchant', 'submitter'])))->resolve($request),
        ]);
    }
}
