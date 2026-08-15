<?php

declare(strict_types=1);

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Http\Resources\MerchantBranchResource;
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
 */
class BranchesController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return MerchantBranchResource::collection(
            $this->merchant($request)->branches()->orderBy('id')->get(),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateBranch($request, creating: true);

        $this->assertCoordinatePair($validated['lat'] ?? null, $validated['lng'] ?? null);

        $branch = $this->merchant($request)->branches()->create($validated);

        return (new MerchantBranchResource($branch))
            ->response($request)
            ->setStatusCode(201);
    }

    public function update(Request $request, int $id): MerchantBranchResource
    {
        /** @var MerchantBranch $branch */
        $branch = $this->merchant($request)->branches()->findOrFail($id);

        $validated = $this->validateBranch($request, creating: false);

        // The coordinate pair stays a pair AFTER the merge: patching one
        // half onto a branch whose other half is null would leave a
        // meaningless lone coordinate.
        $this->assertCoordinatePair(
            array_key_exists('lat', $validated) ? $validated['lat'] : $branch->lat,
            array_key_exists('lng', $validated) ? $validated['lng'] : $branch->lng,
        );

        $branch->fill($validated)->save();

        return new MerchantBranchResource($branch->refresh());
    }

    public function destroy(Request $request, int $id): Response|JsonResponse
    {
        $merchant = $this->merchant($request);

        return DB::transaction(function () use ($merchant, $id) {
            /** @var MerchantBranch $branch */
            $branch = $merchant->branches()->lockForUpdate()->findOrFail($id);

            $referenced = Transaction::query()->where('branch_id', $branch->id)->exists()
                || Promotion::query()->where('branch_id', $branch->id)->exists();

            if ($referenced) {
                return new JsonResponse([
                    'message' => 'This branch is referenced by transactions or promotions and cannot be deleted.',
                    'code' => 'branch_referenced',
                ], 409);
            }

            $branch->delete();

            return response()->noContent();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function validateBranch(Request $request, bool $creating): array
    {
        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
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
        /** @var MerchantUser $user */
        $user = $request->user('merchant');

        return $user->merchant;
    }
}
