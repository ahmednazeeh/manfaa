<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Cashback\DuplicateInvoiceException;
use App\Domain\Cashback\MerchantNotActiveException;
use App\Domain\Cashback\NoEffectiveRateException;
use App\Domain\Claims\ClaimAlreadyResolvedException;
use App\Domain\Claims\ClaimApprovalService;
use App\Domain\Claims\ClaimBelowMinimumException;
use App\Domain\Claims\ClaimState;
use App\Http\Controllers\Controller;
use App\Http\Resources\ClaimAdminResource;
use App\Models\AdminUser;
use App\Models\Claim;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The admin claims queue (§10 apps/admin, §12 Phase 3). Approval mints the
 * missed transaction through ClaimApprovalService — origin 'claim', the
 * merchant's rate at the purchase date, normal ceiling money, merchant-
 * funded accrual. Rejection requires a written reason; it becomes the
 * resolution_note the customer sees.
 */
class ClaimsController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'state' => ['sometimes', 'string', Rule::enum(ClaimState::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Claim::query()
            ->with(['merchant:id,name,slug', 'customer:id,customer_code,name'])
            ->orderBy('id');

        if (isset($validated['state'])) {
            $query->where('state', $validated['state']);
        }

        return ClaimAdminResource::collection(
            $query->paginate((int) ($validated['per_page'] ?? 25))->appends($request->query()),
        );
    }

    public function approve(Request $request, int $id, ClaimApprovalService $approvals): ClaimAdminResource
    {
        $validated = $request->validate([
            'resolution_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $claim = Claim::query()->findOrFail($id);

        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        try {
            $approvals->approve($claim, $admin, $validated['resolution_note'] ?? null);
        } catch (ClaimAlreadyResolvedException|DuplicateInvoiceException $e) {
            abort(409, $e->getMessage());
        } catch (MerchantNotActiveException|ClaimBelowMinimumException|NoEffectiveRateException $e) {
            abort(422, $e->getMessage());
        }

        return new ClaimAdminResource(
            $claim->refresh()->load(['merchant:id,name,slug', 'customer:id,customer_code,name']),
        );
    }

    public function reject(Request $request, int $id): ClaimAdminResource
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        // Serialised against approve(), which locks the claim row for the
        // whole minting transaction: re-read under the same row lock and
        // re-check state, so a reject racing a concurrent approval waits for
        // it and then 409s instead of overwriting 'approved' on a claim
        // whose minted transaction and accrual journal already stand.
        $claim = DB::transaction(function () use ($id, $admin, $validated): Claim {
            $claim = Claim::query()->whereKey($id)->lockForUpdate()->firstOrFail();

            if (! in_array($claim->state, [ClaimState::Open->value, ClaimState::InReview->value], true)) {
                abort(409, sprintf('Claim #%d is already %s.', $claim->id, $claim->state));
            }

            $claim->update([
                'state' => ClaimState::Rejected->value,
                'resolved_by' => $admin->id,
                'resolved_at' => CarbonImmutable::now('UTC'),
                'resolution_note' => $validated['reason'],
            ]);

            return $claim;
        });

        return new ClaimAdminResource(
            $claim->load(['merchant:id,name,slug', 'customer:id,customer_code,name']),
        );
    }
}
