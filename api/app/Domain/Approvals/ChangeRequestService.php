<?php

declare(strict_types=1);

namespace App\Domain\Approvals;

use App\Domain\Discovery\DiscoveryService;
use App\Domain\Notifications\NotificationService;
use App\Domain\Notifications\NotificationTemplateKey;
use App\Domain\Onboarding\MerchantLogo;
use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantBranch;
use App\Models\MerchantChangeRequest;
use App\Models\MerchantUser;
use App\Models\Promotion;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Admin approval for store edits and new branches (MR9, design decided
 * 2026-08-18). The line the owner drew is **claims vs operations**: what a
 * shopper reads and trusts queues for review, what is the merchant's own
 * business never waits.
 *
 * GATED — the claim (PLAN-merchant-app.md §MR9):
 *   name, name_dv, category, channel, eligibility_basis, website_url, logo,
 *   and the whole branch estate (create / update / delete).
 *
 * INSTANT — corrective, not a claim:
 *   contact_email, contact_phone, support_phone. A wrong number means
 *   customers cannot reach the store, so holding the fix for review would
 *   only prolong the harm. (Everything outside the profile PATCH — the
 *   cashback rate, product-category rules, staff, roles, bank account,
 *   preferences, promotions — was never in this file's scope and stays
 *   instant by construction.)
 *
 * WHO IS GATED: live stores only, i.e. `active` or `suspended`. A store
 * still in draft / pending_review / rejected writes DIRECTLY, because its
 * one write path is the setup wizard and the whole record is about to be
 * reviewed anyway — gating it would deadlock onboarding behind an approval
 * queue that reviews the store, not its edits. Admin-panel writes are not
 * routed through here at all: admins are the approvers.
 */
final class ChangeRequestService
{
    /**
     * Profile keys that apply the moment they are sent. Everything else the
     * profile PATCH validates is GATED — the default is deliberately
     * fail-closed, so a field added to that endpoint tomorrow queues for
     * review until someone decides, in writing, that it is corrective.
     */
    public const array INSTANT_PROFILE_KEYS = ['contact_email', 'contact_phone', 'support_phone'];

    /** The gated half, as the wire names them (logo rides `logo_path`). */
    public const array GATED_PROFILE_KEYS = [
        'name', 'name_dv', 'category', 'channel', 'eligibility_basis', 'website_url', 'logo_path',
    ];

    /** Live stores — and only these — have their claims reviewed. */
    public const array GATED_STATUSES = ['active', 'suspended'];

    public function __construct(private readonly NotificationService $notifications) {}

    /** Does this store's public identity need approval to move? */
    public static function gates(Merchant $merchant): bool
    {
        return in_array($merchant->status, self::GATED_STATUSES, true);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{instant: array<string, mixed>, gated: array<string, mixed>}
     */
    public static function splitProfileInput(array $validated): array
    {
        $instant = array_intersect_key($validated, array_flip(self::INSTANT_PROFILE_KEYS));

        return ['instant' => $instant, 'gated' => array_diff_key($validated, $instant)];
    }

    /**
     * Queue a profile change (including the logo, whose file is already
     * staged on the logos disk and arrives here as `logo_path`).
     *
     * Returns NULL when nothing was actually proposed: the panels PATCH the
     * whole form, so a save that only moves the contact number arrives here
     * carrying the store's own name and category unchanged. Diffing against
     * the live row — rather than queueing whatever was sent — is what keeps
     * "I fixed my phone number" from parking the shop's identity in a review
     * queue for a day.
     *
     * @param  array<string, mixed>  $proposed  gated keys only
     */
    public function submitProfileChange(Merchant $merchant, MerchantUser $actor, array $proposed): ?MerchantChangeRequest
    {
        $current = fn (string $key): mixed => $merchant->getAttribute($key);

        return $this->submit(
            $merchant,
            $actor,
            ChangeKind::Profile,
            null,
            $this->changedOnly($proposed, $current),
            $current,
        );
    }

    /**
     * Queue a branch change.
     *
     * `branch_create` and `branch_delete` are whole acts — there is nothing
     * to diff, so they always queue and never answer null. `branch_update`
     * diffs against the branch exactly as the profile does, and answers null
     * when the save moved nothing.
     *
     * @param  array<string, mixed>  $proposed
     */
    public function submitBranchChange(
        Merchant $merchant,
        MerchantUser $actor,
        ChangeKind $kind,
        ?MerchantBranch $branch,
        array $proposed = [],
    ): ?MerchantChangeRequest {
        $current = fn (string $key): mixed => $branch?->getAttribute($key);

        if ($kind === ChangeKind::BranchUpdate) {
            $proposed = $this->changedOnly($proposed, $current);
        }

        if ($kind === ChangeKind::BranchDelete) {
            $proposed = [];
        }

        return $this->submit(
            $merchant,
            $actor,
            $kind,
            $branch,
            $proposed,
            $current,
            // A removal proposes nothing, so its snapshot is the branch
            // itself: that is the whole of what the reviewer is deciding on.
            snapshotKeys: $kind === ChangeKind::BranchDelete ? ['name', 'address', 'lat', 'lng'] : null,
            always: $kind !== ChangeKind::BranchUpdate,
        );
    }

    /**
     * Apply the change and mark the row reviewed — atomically, so a request
     * can never read `approved` while the store still says otherwise.
     */
    public function approve(MerchantChangeRequest $request, AdminUser $admin): MerchantChangeRequest
    {
        $merchant = $request->merchant;

        DB::transaction(function () use ($request, $admin, $merchant): void {
            $locked = MerchantChangeRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw ChangeRequestException::notPending($locked->status);
            }

            match ($locked->kind) {
                ChangeKind::Profile => $this->applyProfile($merchant, $locked),
                ChangeKind::BranchCreate => $this->applyBranchCreate($merchant, $locked),
                ChangeKind::BranchUpdate => $this->applyBranchUpdate($merchant, $locked),
                ChangeKind::BranchDelete => $this->applyBranchDelete($merchant, $locked),
            };

            $locked->forceFill([
                'status' => MerchantChangeRequest::APPROVED,
                'reviewed_by' => $admin->id,
                'reviewed_at' => CarbonImmutable::now('UTC'),
            ])->save();
        });

        // Every gated field is a PUBLIC one — that is what made it gated —
        // so the 60s read models must not outlive the approval.
        DiscoveryService::forgetMerchant($merchant);

        $this->notify($request, NotificationTemplateKey::StoreChangeApproved, $merchant->refresh());

        return $request->refresh();
    }

    /** Refused, with the reason the merchant is owed. Nothing is applied. */
    public function reject(MerchantChangeRequest $request, AdminUser $admin, string $reason): MerchantChangeRequest
    {
        DB::transaction(function () use ($request, $admin, $reason): void {
            $locked = MerchantChangeRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw ChangeRequestException::notPending($locked->status);
            }

            $locked->forceFill([
                'status' => MerchantChangeRequest::REJECTED,
                'reviewed_by' => $admin->id,
                'reviewed_at' => CarbonImmutable::now('UTC'),
                'rejected_reason' => $reason,
            ])->save();
        });

        // A refused logo is bytes nobody will ever serve.
        $this->discardStagedLogo($request->payload ?? [], keeping: null);

        $this->notify($request, NotificationTemplateKey::StoreChangeRejected, $request->merchant, $reason);

        return $request->refresh();
    }

    /**
     * What this store currently has waiting — the merchant surfaces' "pending
     * review" state, oldest first.
     *
     * @return Collection<int, MerchantChangeRequest>
     */
    public function pendingFor(Merchant $merchant, ?ChangeKind $kind = null): Collection
    {
        return MerchantChangeRequest::query()
            ->where('merchant_id', $merchant->getKey())
            ->where('status', MerchantChangeRequest::PENDING)
            ->when($kind !== null, fn ($query) => $query->where('kind', $kind->value))
            ->orderBy('id')
            ->get();
    }

    /**
     * The one place a request is written.
     *
     * SUPERSEDING is scoped to the TARGET, not merely to the kind: the
     * merchant's pending profile request, or the pending request against
     * THIS branch. Two new branches queued back to back are two different
     * objects and must not eat each other — the rule exists so nobody is
     * stuck behind their own earlier request, and a second branch is not
     * stuck behind the first.
     *
     * CARRY-FORWARD is what makes superseding safe. The new row inherits
     * every key the superseded one held that this submission did not mention
     * at all — so uploading a logo does not silently drop a pending rename
     * (different endpoint, same kind), and a profile PATCH does not drop a
     * pending logo. Keys this submission DID mention always win.
     *
     * @param  array<string, mixed>  $proposed  already reduced to real changes
     * @param  callable(string): mixed  $current  the live value of a key
     * @param  ?list<string>  $snapshotKeys  overrides "snapshot what changed"
     */
    private function submit(
        Merchant $merchant,
        MerchantUser $actor,
        ChangeKind $kind,
        ?MerchantBranch $branch,
        array $proposed,
        callable $current,
        ?array $snapshotKeys = null,
        bool $always = false,
    ): ?MerchantChangeRequest {
        return DB::transaction(function () use ($merchant, $actor, $kind, $branch, $proposed, $current, $snapshotKeys, $always): ?MerchantChangeRequest {
            $existing = $this->lockPendingSiblings($merchant, $kind, $branch);

            if ($proposed === [] && ! $always) {
                // Nothing proposed that is not already true. The earlier
                // request stands untouched — a no-op save must not withdraw
                // a change the owner is still waiting on.
                //
                // Checked BEFORE the carry-forward below, and that order is
                // the whole point: the panels PATCH the whole form, so a
                // contact-phone save arrives here carrying the store's own
                // name back at us. Merging the pending row's keys in first
                // would make this submission look like a proposal, supersede
                // the row the owner is waiting on and re-queue an identical
                // copy — resetting its submitted_at, its submitter and its
                // place in the reviewer's oldest-first queue every time
                // anyone fixed a phone number.
                return null;
            }

            foreach ($existing as $row) {
                // Across kinds there is nothing to inherit: a removal that
                // replaces a rename must not arrive carrying the new name.
                if ($row->kind !== $kind) {
                    continue;
                }

                foreach ((array) $row->payload as $key => $value) {
                    if (! array_key_exists($key, $proposed)) {
                        $proposed[$key] = $value;
                    }
                }
            }

            foreach ($existing as $row) {
                $row->forceFill(['status' => MerchantChangeRequest::SUPERSEDED])->save();
                $this->discardStagedLogo((array) $row->payload, keeping: $proposed['logo_path'] ?? null);
            }

            $snapshot = [];

            foreach ($snapshotKeys ?? array_keys($proposed) as $key) {
                $snapshot[$key] = $current($key);
            }

            if ($branch !== null) {
                // The reviewer needs to know WHICH branch even after the
                // approval of a removal nulls the column.
                $snapshot['id'] = $branch->id;
            }

            return MerchantChangeRequest::query()->create([
                'merchant_id' => $merchant->id,
                'kind' => $kind,
                'branch_id' => $branch?->id,
                'payload' => $proposed,
                'snapshot' => $snapshot,
                'status' => MerchantChangeRequest::PENDING,
                'submitted_by' => $actor->id,
            ]);
        });
    }

    /**
     * The pending rows this submission replaces, locked. Branch kinds
     * collapse onto the branch: a queued rename and a queued removal of the
     * same branch are two answers to one question, and the latest one is the
     * merchant's.
     *
     * @return Collection<int, MerchantChangeRequest>
     */
    private function lockPendingSiblings(Merchant $merchant, ChangeKind $kind, ?MerchantBranch $branch): Collection
    {
        if ($kind === ChangeKind::BranchCreate) {
            // Each queued branch is a distinct new place, not a revision of
            // the last one — two shops opening in the same week must both
            // reach the queue.
            return new Collection;
        }

        $query = MerchantChangeRequest::query()
            ->where('merchant_id', $merchant->id)
            ->where('status', MerchantChangeRequest::PENDING)
            ->lockForUpdate();

        if ($branch === null) {
            return $query->where('kind', $kind->value)->get();
        }

        return $query
            ->where('branch_id', $branch->id)
            ->whereIn('kind', [ChangeKind::BranchUpdate->value, ChangeKind::BranchDelete->value])
            ->get();
    }

    /**
     * The proposed values that are not already true.
     *
     * @param  array<string, mixed>  $proposed
     * @param  callable(string): mixed  $current
     * @return array<string, mixed>
     */
    private function changedOnly(array $proposed, callable $current): array
    {
        return array_filter(
            $proposed,
            fn (string $key): bool => ! self::same($proposed[$key], $current($key)),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Wire values arrive as strings, floats and nulls while columns hand back
     * strings (decimal(10,7) coordinates above all), so identity is compared
     * on the string form — with null kept distinct from '', because clearing
     * a field and blanking it are the same intent but "unset" is a value the
     * column can hold.
     */
    private static function same(mixed $proposed, mixed $current): bool
    {
        if ($proposed === null || $current === null) {
            return $proposed === null && $current === null;
        }

        if (is_numeric($proposed) && is_numeric($current)) {
            return (float) $proposed === (float) $current;
        }

        return (string) $proposed === (string) $current;
    }

    private function applyProfile(Merchant $merchant, MerchantChangeRequest $request): void
    {
        $payload = (array) $request->payload;

        /** @var Merchant $locked */
        $locked = Merchant::query()->whereKey($merchant->getKey())->lockForUpdate()->firstOrFail();

        $previousLogo = $locked->logo_path;

        $locked->fill($payload)->save();

        // Only once the new path is committed to the row: a store must never
        // be left pointing at a file that has been deleted.
        if (array_key_exists('logo_path', $payload)
            && $previousLogo !== null && $previousLogo !== '' && $previousLogo !== $payload['logo_path']) {
            Storage::disk(MerchantLogo::DISK)->delete($previousLogo);
        }
    }

    private function applyBranchCreate(Merchant $merchant, MerchantChangeRequest $request): void
    {
        $merchant->branches()->create((array) $request->payload);
    }

    private function applyBranchUpdate(Merchant $merchant, MerchantChangeRequest $request): void
    {
        $this->lockedBranch($merchant, $request)->fill((array) $request->payload)->save();
    }

    private function applyBranchDelete(Merchant $merchant, MerchantChangeRequest $request): void
    {
        $branch = $this->lockedBranch($merchant, $request);

        $referenced = Transaction::query()->where('branch_id', $branch->id)->exists()
            || Promotion::query()->where('branch_id', $branch->id)->exists();

        if ($referenced) {
            throw ChangeRequestException::branchReferenced();
        }

        $branch->delete();
    }

    private function lockedBranch(Merchant $merchant, MerchantChangeRequest $request): MerchantBranch
    {
        /** @var ?MerchantBranch $branch */
        $branch = $merchant->branches()->lockForUpdate()->find($request->branch_id);

        if ($branch === null) {
            throw ChangeRequestException::branchMissing();
        }

        return $branch;
    }

    /**
     * A staged logo that will never be applied is deleted from the disk —
     * unless the row that replaces this one carries the very same file.
     *
     * @param  array<string, mixed>  $payload
     */
    private function discardStagedLogo(array $payload, ?string $keeping): void
    {
        $path = $payload['logo_path'] ?? null;

        if (! is_string($path) || $path === '' || $path === $keeping) {
            return;
        }

        Storage::disk(MerchantLogo::DISK)->delete($path);
    }

    private function notify(
        MerchantChangeRequest $request,
        NotificationTemplateKey $key,
        Merchant $merchant,
        ?string $reason = null,
    ): void {
        $this->notifications->sendToMerchantStaff(
            $key,
            $merchant,
            [
                'change' => $request->kind->label(),
                'store' => (string) $merchant->name,
                'reason' => (string) $reason,
            ],
            $request->kind->permission(),
        );
    }
}
