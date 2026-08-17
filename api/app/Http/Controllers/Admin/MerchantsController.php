<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Cashback\TransactionState;
use App\Domain\Discovery\DiscoveryService;
use App\Domain\Money\Percent;
use App\Domain\Onboarding\OnboardingService;
use App\Domain\Standing\StaleHolds;
use App\Domain\Standing\SuspensionService;
use App\Http\Controllers\Controller;
use App\Http\Resources\MerchantStaffResource;
use App\Models\Merchant;
use App\Models\MerchantBranch;
use App\Models\MerchantNotice;
use App\Models\StoreCategory;
use App\Models\Zone;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MerchantsController extends Controller
{
    /**
     * Merchant standing for the admin panel: status plus outstanding and
     * overdue unfunded-payable totals, summed from the STORED line integers —
     * and the stale-hold backlog (on_hold unchanged for 30+ days), so held
     * rows under fraud/dispute review stay visible instead of ageing quietly.
     */
    public function index(StaleHolds $staleHolds): JsonResponse
    {
        $now = CarbonImmutable::now('UTC');

        $rows = Merchant::query()
            ->leftJoin('transactions', function (JoinClause $join) {
                $join->on('transactions.merchant_id', '=', 'merchants.id')
                    ->where('transactions.state', '=', TransactionState::PayableUnfunded->value);
            })
            ->leftJoinSub($staleHolds->perMerchant($now), 'stale_holds', 'stale_holds.merchant_id', '=', 'merchants.id')
            ->groupBy('merchants.id')
            ->orderBy('merchants.name')
            ->selectRaw(<<<'SQL'
                merchants.id,
                merchants.name,
                merchants.slug,
                merchants.status,
                merchants.featured,
                COUNT(transactions.id) AS open_count,
                COALESCE(SUM(transactions.cashback_laari + transactions.fee_laari + transactions.fee_gst_laari), 0) AS outstanding_laari,
                COALESCE(SUM(transactions.cashback_laari + transactions.fee_laari + transactions.fee_gst_laari)
                    FILTER (WHERE transactions.due_at < ?), 0) AS overdue_laari,
                MIN(transactions.due_at) AS oldest_due_at,
                COALESCE(MAX(stale_holds.stale_hold_count), 0) AS stale_hold_count,
                COALESCE(MAX(stale_holds.stale_hold_laari), 0) AS stale_hold_laari
                SQL, [$now])
            ->get();

        return response()->json([
            'data' => $rows->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'slug' => $row->slug,
                'status' => $row->status,
                'featured' => (bool) $row->featured,
                'open_payable_count' => (int) $row->open_count,
                'outstanding_laari' => (int) $row->outstanding_laari,
                'overdue_laari' => (int) $row->overdue_laari,
                'oldest_due_at' => $row->oldest_due_at !== null
                    ? CarbonImmutable::parse($row->oldest_due_at)->utc()->toIso8601String()
                    : null,
                'stale_hold_count' => (int) $row->stale_hold_count,
                'stale_hold_laari' => (int) $row->stale_hold_laari,
            ])->values(),
        ]);
    }

    /**
     * One merchant in full for the admin detail drawer: the public profile
     * fields, lifecycle timestamps, the standing rate in force, the branch
     * estate with each pin's resolved zone, the panel accounts, and the
     * same unfunded-payable standing sums the index shows. Read-only and
     * open to any admin; every write beside it is superadmin-gated.
     */
    public function show(Merchant $merchant, OnboardingService $onboarding): JsonResponse
    {
        return response()->json(['data' => $this->detail($merchant, $onboarding)]);
    }

    /**
     * The superadmin edit over the merchant's public profile — the same
     * fields, rules and normalisation as the merchant's own settings PATCH
     * (Merchant\ProfileController), because two write paths with different
     * validation would let one accept what the other refuses. The one
     * addition is `name`/`name_dv`: renaming a business is an identity
     * change and deliberately admin-only. The slug never moves with a
     * rename — it is the address on every printed QR card in circulation.
     */
    public function update(Request $request, Merchant $merchant, OnboardingService $onboarding): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'name_dv' => ['sometimes', 'nullable', 'string', 'max:120'],
            // Curated categories only (§1) — with the same one exception the
            // merchant's own save enjoys: the value already held is always
            // accepted, so a retired category never traps the whole form.
            'category' => ['sometimes', 'nullable', 'string', 'max:80', $this->categoryRule($merchant)],
            'channel' => ['sometimes', 'string', Rule::in(OnboardingService::CHANNELS)],
            'eligibility_basis' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'contact_email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'support_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'website_url' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $merchant->fill($validated)->save();

        // Name, category, channel and the terms text all render on the
        // public card and store page — 60s read models (DiscoveryService).
        // Unconditional, exactly like the merchant's own save: reasoning
        // about which field is public is the kind of subtlety that rots.
        DiscoveryService::forgetMerchant($merchant);

        return response()->json(['data' => $this->detail($merchant->refresh(), $onboarding)]);
    }

    /**
     * The superadmin's manual suspension — the conduct lever beside the §7
     * clock's automatic credit control. Requires a reason: it lands in the
     * append-only notice trail as {manual: true, reason, admin_id}, which is
     * both the audit record and what keeps the automatic reinstate sweep
     * from quietly reopening the store. The service drops the discovery
     * read model, so the store vanishes from the public feed immediately.
     */
    public function suspend(Request $request, Merchant $merchant, SuspensionService $suspensions): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        if ($merchant->status !== 'active') {
            abort(409, sprintf('Merchant #%d is %s — only an active merchant can be suspended.', $merchant->id, $merchant->status));
        }

        $suspensions->suspendManually($merchant, $validated['reason'], (int) $request->user()->getKey());

        return response()->json([
            'data' => [
                'id' => $merchant->id,
                'status' => $merchant->refresh()->status,
            ],
        ]);
    }

    /**
     * The manual path back for a suspended merchant — including one whose
     * overdue debt was cleared by the 90-day write-off, which the automatic
     * manfaa:reinstate sweep deliberately never touches. Whether such
     * merchants should ever come back automatically is an open PLAN.md (§7)
     * product decision; until it is made, this note-carrying admin action is
     * the safe default and the only way back.
     */
    public function reinstate(Request $request, Merchant $merchant, SuspensionService $suspensions): JsonResponse
    {
        $validated = $request->validate([
            'note' => ['required', 'string', 'max:1000'],
        ]);

        if ($merchant->status !== 'suspended') {
            abort(409, sprintf('Merchant #%d is %s — only a suspended merchant can be reinstated.', $merchant->id, $merchant->status));
        }

        $suspensions->reinstateManually($merchant, $validated['note'], (int) $request->user()->getKey());

        return response()->json([
            'data' => [
                'id' => $merchant->id,
                'status' => $merchant->refresh()->status,
            ],
        ]);
    }

    /**
     * The editorial flag behind the storefront's "featured" shelf — purely
     * placement, no money or lifecycle rides on it. The discovery read model
     * is dropped on every actual flip (DiscoveryService::forgetMerchant), so
     * the shelf reflects the admin's choice within a request rather than
     * after the cache TTL. Writing the value already held is a no-op that
     * still answers 200 — and leaves the cache alone.
     */
    public function featured(Request $request, Merchant $merchant): JsonResponse
    {
        $validated = $request->validate([
            'featured' => ['required', 'boolean'],
        ]);

        $featured = (bool) $validated['featured'];

        if ((bool) $merchant->featured !== $featured) {
            $merchant->update(['featured' => $featured]);
            DiscoveryService::forgetMerchant($merchant);
        }

        return response()->json([
            'data' => [
                'id' => $merchant->id,
                'featured' => (bool) $merchant->featured,
            ],
        ]);
    }

    /**
     * The append-only notice trail for one merchant — the evidence that every
     * §7 clock step was recorded.
     */
    public function notices(Merchant $merchant): JsonResponse
    {
        $notices = MerchantNotice::query()
            ->where('merchant_id', $merchant->id)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return response()->json([
            'data' => $notices->map(fn (MerchantNotice $notice) => [
                'id' => $notice->id,
                'type' => $notice->type,
                'channel' => $notice->channel,
                'payload' => $notice->payload,
                'sent_at' => $notice->sent_at->toIso8601String(),
            ])->values(),
        ]);
    }

    /**
     * The full merchant record the detail drawer renders — shared by show()
     * and the edit PATCH so a save answers with exactly what a re-read would.
     *
     * @return array<string, mixed>
     */
    private function detail(Merchant $merchant, OnboardingService $onboarding): array
    {
        $now = CarbonImmutable::now('UTC');

        $standing = DB::table('transactions')
            ->where('merchant_id', $merchant->id)
            ->where('state', TransactionState::PayableUnfunded->value)
            ->selectRaw(<<<'SQL'
                COUNT(*) AS open_count,
                COALESCE(SUM(cashback_laari + fee_laari + fee_gst_laari), 0) AS outstanding_laari,
                COALESCE(SUM(cashback_laari + fee_laari + fee_gst_laari)
                    FILTER (WHERE due_at < ?), 0) AS overdue_laari,
                MIN(due_at) AS oldest_due_at
                SQL, [$now])
            ->first();

        $branches = $merchant->branches()->orderBy('id')->get();

        // One lookup for every zone the estate touches; the branch rows only
        // carry the id (recomputed from the pin at write time, ZoneAssigner).
        $zoneNames = Zone::query()
            ->whereIn('id', $branches->pluck('zone_id')->filter()->unique())
            ->pluck('name', 'id');

        return [
            'id' => $merchant->id,
            'name' => $merchant->name,
            'name_dv' => $merchant->name_dv,
            'slug' => $merchant->slug,
            'status' => $merchant->status,
            'featured' => (bool) $merchant->featured,
            'category' => $merchant->category,
            'channel' => $merchant->channel,
            'eligibility_basis' => $merchant->eligibility_basis,
            'contact_email' => $merchant->contact_email,
            'contact_phone' => $merchant->contact_phone,
            'support_phone' => $merchant->support_phone,
            'website_url' => $merchant->website_url,
            'logo_url' => $onboarding->logoUrl($merchant),
            // PLAN §1 wire format: 2-decimal percent string, never bp.
            'cashback_rate_percent' => Percent::formatOrNull($onboarding->currentRateBp($merchant)),
            'created_at' => $merchant->created_at?->toIso8601String(),
            'submitted_at' => $merchant->submitted_at?->toIso8601String(),
            'approved_at' => $merchant->approved_at?->toIso8601String(),
            'standing' => [
                'open_payable_count' => (int) $standing->open_count,
                'outstanding_laari' => (int) $standing->outstanding_laari,
                'overdue_laari' => (int) $standing->overdue_laari,
                'oldest_due_at' => $standing->oldest_due_at !== null
                    ? CarbonImmutable::parse($standing->oldest_due_at)->utc()->toIso8601String()
                    : null,
            ],
            'branches' => $branches->map(fn (MerchantBranch $branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'address' => $branch->address,
                'lat' => $branch->lat === null ? null : (float) $branch->lat,
                'lng' => $branch->lng === null ? null : (float) $branch->lng,
                'zone' => $branch->zone_id === null ? null : [
                    'id' => (int) $branch->zone_id,
                    'name' => $zoneNames[$branch->zone_id] ?? null,
                ],
            ])->values(),
            'staff' => $merchant->users()
                ->with('role')
                ->orderBy('id')
                ->get()
                ->map(fn ($user) => (new MerchantStaffResource($user))->resolve())
                ->values(),
        ];
    }

    /**
     * Same rule as the merchant's own profile save: a category must be an
     * ACTIVE curated slug, except the value the store already holds — a
     * retired category must stay advisory, never a save trap.
     */
    private function categoryRule(Merchant $merchant): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($merchant): void {
            if ($value === $merchant->category) {
                return;
            }

            $exists = StoreCategory::query()
                ->where('slug', $value)
                ->where('active', true)
                ->exists();

            if (! $exists) {
                $fail('The selected category is not available.');
            }
        };
    }
}
