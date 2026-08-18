<?php

declare(strict_types=1);

namespace App\Http\Controllers\Merchant;

use App\Domain\Approvals\ChangeKind;
use App\Domain\Approvals\ChangeRequestService;
use App\Http\Controllers\Controller;
use App\Http\Resources\MerchantBranchResource;
use App\Http\Resources\MerchantChangeRequestResource;
use App\Models\Merchant;
use App\Models\MerchantBranch;
use App\Models\MerchantUser;
use App\Models\Promotion;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Manager-or-owner management of merchant branches (PLAN §1 puts branches
 * in the manager tier). Every {id} resolves through the
 * authenticated merchant's own relation — another merchant's branch is
 * indistinguishable from a missing one.
 *
 * DELETE is hard only while nothing references the branch: a branch with
 * transactions (or branch-scoped promotions) is history that must keep
 * resolving, so deletion answers 409 `branch_referenced` and the panel's
 * soft alternative is simply to stop using it.
 *
 * MR9: for a LIVE store (active or suspended) every one of these writes
 * QUEUES for admin review instead of applying — a branch is an address a
 * shopper travels to, so growing, renaming, re-pinning or dropping the
 * estate is a public claim. Each queued write answers 202 with the change
 * request; the estate itself moves only when an admin approves. A store
 * that is not live still writes straight through, because before approval
 * the whole record is what the review queue is looking at anyway.
 */
class BranchesController extends Controller
{
    public function __construct(private readonly ChangeRequestService $changes) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $merchant = $this->merchant($request);

        return MerchantBranchResource::collection(
            $merchant->branches()->orderBy('id')->get(),
        )->additional([
            // What this store has waiting on a reviewer — the panels' and
            // the app's "pending review" state, carrying the proposed
            // values so the owner sees exactly what is queued and against
            // which branch (§MR9).
            'meta' => [
                'pending_changes' => MerchantChangeRequestResource::collection(
                    $this->changes->pendingFor($merchant)->filter(fn ($row): bool => $row->kind->isBranch())->values(),
                )->resolve($request),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateBranch($request, creating: true);

        $this->assertCoordinatePair($validated['lat'] ?? null, $validated['lng'] ?? null);

        $merchant = $this->merchant($request);

        if (ChangeRequestService::gates($merchant)) {
            return MerchantChangeRequestResource::accepted($this->changes->submitBranchChange(
                $merchant, $this->actor($request), ChangeKind::BranchCreate, null, $validated,
            ));
        }

        $branch = $merchant->branches()->create($validated);

        return (new MerchantBranchResource($branch))
            ->response($request)
            ->setStatusCode(201);
    }

    public function update(Request $request, int $id): MerchantBranchResource|JsonResponse
    {
        $merchant = $this->merchant($request);

        /** @var MerchantBranch $branch */
        $branch = $merchant->branches()->findOrFail($id);

        $validated = $this->validateBranch($request, creating: false);

        // The coordinate pair stays a pair AFTER the merge: patching one
        // half onto a branch whose other half is null would leave a
        // meaningless lone coordinate.
        $this->assertCoordinatePair(
            array_key_exists('lat', $validated) ? $validated['lat'] : $branch->lat,
            array_key_exists('lng', $validated) ? $validated['lng'] : $branch->lng,
        );

        if (ChangeRequestService::gates($merchant)) {
            $queued = $this->changes->submitBranchChange(
                $merchant, $this->actor($request), ChangeKind::BranchUpdate, $branch, $validated,
            );

            // Nothing genuinely changed — the panel PATCHes the whole
            // dialog, so re-saving an untouched branch is a 200, not a
            // review queue.
            return $queued === null
                ? new MerchantBranchResource($branch)
                : MerchantChangeRequestResource::accepted($queued);
        }

        $branch->fill($validated)->save();

        return new MerchantBranchResource($branch->refresh());
    }

    public function destroy(Request $request, int $id): Response|JsonResponse
    {
        $merchant = $this->merchant($request);

        if (ChangeRequestService::gates($merchant)) {
            /** @var MerchantBranch $branch */
            $branch = $merchant->branches()->findOrFail($id);

            // The referenced check runs at SUBMIT as well as at approve: a
            // branch that can never be deleted must be refused now, to the
            // person who asked, rather than parked in a queue for an admin
            // to discover it cannot be honoured.
            if ($this->referenced($branch)) {
                return $this->branchReferenced();
            }

            return MerchantChangeRequestResource::accepted($this->changes->submitBranchChange(
                $merchant, $this->actor($request), ChangeKind::BranchDelete, $branch,
            ));
        }

        return DB::transaction(function () use ($merchant, $id) {
            /** @var MerchantBranch $branch */
            $branch = $merchant->branches()->lockForUpdate()->findOrFail($id);

            if ($this->referenced($branch)) {
                return $this->branchReferenced();
            }

            $branch->delete();

            return response()->noContent();
        });
    }

    /** History that must keep resolving — see the class docblock. */
    private function referenced(MerchantBranch $branch): bool
    {
        return Transaction::query()->where('branch_id', $branch->id)->exists()
            || Promotion::query()->where('branch_id', $branch->id)->exists();
    }

    private function branchReferenced(): JsonResponse
    {
        return new JsonResponse([
            'message' => 'This branch is referenced by transactions or promotions and cannot be deleted.',
            'code' => 'branch_referenced',
        ], 409);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateBranch(Request $request, bool $creating): array
    {
        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            // Required now (owner decision 2026-08-18): a branch on the map
            // with no address is a pin a customer cannot read. `sometimes`
            // on edit so a PATCH that only moves the pin need not resend it
            // — but if it IS sent, it may not be sent empty.
            'address' => [$creating ? 'required' : 'sometimes', 'string', 'max:1000'],
            'lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'lng' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
        ]);
    }

    /**
     * Coordinates are a nullable PAIR: both set or both null, never one.
     */
    private function assertCoordinatePair(mixed $lat, mixed $lng): void
    {
        if (($lat === null) !== ($lng === null)) {
            abort(422, 'lat and lng must be provided together or both be null.');
        }
    }

    private function merchant(Request $request): Merchant
    {
        return $this->actor($request)->merchant;
    }

    private function actor(Request $request): MerchantUser
    {
        /** @var MerchantUser $user */
        $user = $request->user('merchant');

        return $user;
    }
}
