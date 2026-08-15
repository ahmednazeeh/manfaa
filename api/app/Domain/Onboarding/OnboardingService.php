<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

use App\Domain\Discovery\DiscoveryService;
use App\Domain\Money\Percent;
use App\Domain\Money\Rate;
use App\Domain\Platform\TierScheduleService;
use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\StoreCategory;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The resumable store setup wizard (§1 decision 2026-08-15): profile
 * (curated category, channel, terms, contact), optional logo, initial
 * cashback rate — then submit moves the store draft → pending_review, and
 * the superadmin queue approves (→ active) or rejects (→ rejected, with a
 * reason; the merchant edits and resubmits).
 *
 * setup_state tracks completed step keys so quitting mid-wizard resumes on
 * next login; completeness is nevertheless re-validated from the actual
 * column values at submit — the step map is UI convenience, never the
 * source of truth.
 */
final class OnboardingService
{
    public const array WIZARD_STATUSES = ['draft', 'rejected'];

    /** Statuses whose owner may (re)upload a logo — wizard plus post-approval settings. */
    public const array LOGO_STATUSES = ['draft', 'rejected', 'active'];

    public const array CHANNELS = ['in_store', 'online', 'both'];

    public function __construct(private readonly TierScheduleService $schedules) {}

    /**
     * Everything the wizard needs to resume: completed steps, current field
     * values, the curated category list, rate bounds, and the rejection
     * reason when the store was sent back.
     *
     * @return array<string, mixed>
     */
    public function state(Merchant $merchant): array
    {
        $steps = (array) ($merchant->setup_state ?? []);

        return [
            'status' => $merchant->status,
            'steps' => [
                'profile' => (bool) ($steps['profile'] ?? false),
                'logo' => (bool) ($steps['logo'] ?? false),
                'rate' => (bool) ($steps['rate'] ?? false),
            ],
            'values' => [
                'name' => $merchant->name,
                'slug' => $merchant->slug,
                'category' => $merchant->category,
                'channel' => $merchant->channel,
                'eligibility_basis' => $merchant->eligibility_basis,
                'contact_email' => $merchant->contact_email,
                'contact_phone' => $merchant->contact_phone,
                'logo_url' => $this->logoUrl($merchant),
                // PLAN §1 wire format: 2-decimal percent strings, null
                // until the wizard's rate step is done.
                'cashback_rate_percent' => Percent::formatOrNull($this->currentRateBp($merchant)),
            ],
            'rate_bounds' => [
                'min_percent' => Percent::format(Rate::MIN_CASHBACK_BP),
                'max_percent' => Percent::format($this->schedules->activeCeiling()),
            ],
            'categories' => StoreCategory::query()
                ->where('active', true)
                ->orderBy('sort')
                ->orderBy('slug')
                ->get(['slug', 'name_en', 'name_dv'])
                ->map(fn (StoreCategory $c): array => [
                    'slug' => $c->slug,
                    'name_en' => $c->name_en,
                    'name_dv' => $c->name_dv,
                ])
                ->all(),
            'submitted_at' => $merchant->submitted_at?->toIso8601String(),
            'rejected_reason' => $merchant->status === 'rejected' ? $merchant->rejected_reason : null,
        ];
    }

    /**
     * The profile step. $validated arrives controller-validated (curated
     * category slug, channel enum, lengths); this only persists and marks
     * the step.
     *
     * @param  array<string, mixed>  $validated
     */
    public function updateProfile(Merchant $merchant, array $validated): Merchant
    {
        $this->assertEditable($merchant);

        $merchant->fill($validated);
        $this->markStep($merchant, 'profile');
        $merchant->save();

        return $merchant;
    }

    /**
     * Stores the (already-validated) logo on the PRIVATE `logos` disk at
     * merchants/{id}/{uuid}.{ext} (MerchantLogo), deleting any previous file
     * first so a replace never strands the old image.
     *
     * Nothing under that disk is reachable over HTTP: the logo is read only
     * through MerchantLogoController, which serves it publicly while the
     * store is active and to the store's own users or an admin otherwise.
     * That is what keeps an unapproved store's branding off the open web —
     * the old public-disk path (/storage/merchants/{id}/logo.png) was
     * world-readable and guessable from the merchant id alone.
     *
     * Allowed for active merchants too (post-approval logo change) — the
     * caller gates on LOGO_STATUSES.
     *
     * @return string the logo URL (content-versioned; see MerchantLogo)
     */
    public function storeLogo(Merchant $merchant, UploadedFile $file): string
    {
        $extension = strtolower($file->extension());

        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        $disk = Storage::disk(MerchantLogo::DISK);

        $previous = $merchant->logo_path;

        $path = $file->storeAs(
            'merchants/'.$merchant->id,
            Str::uuid()->toString().'.'.$extension,
            MerchantLogo::DISK,
        );

        if ($path === false) {
            throw OnboardingException::logoWriteFailed();
        }

        // Only after the replacement is safely on disk: a failed write must
        // never leave the store with no logo at all.
        if ($previous !== null && $previous !== '' && $previous !== $path) {
            $disk->delete($previous);
        }

        $merchant->logo_path = $path;
        $this->markStep($merchant, 'logo');
        $merchant->save();

        // An active merchant's logo is public the moment it changes; the
        // 60s read-model cache would otherwise keep serving the old URL.
        if ($merchant->status === 'active') {
            DiscoveryService::forgetMerchant($merchant);
        }

        return (string) MerchantLogo::url($merchant->slug, $path);
    }

    /**
     * The rate step: writes the store's INITIAL standing rate, effective
     * now, priced against the live fee tier schedule (coverage lock + §4
     * bounds — TierScheduleService::assertPricedThrough throws
     * RateNotPricedException above the active ceiling).
     *
     * While the store has never earned a transaction the previous initial
     * row is REPLACED (deleting a row no sale ever resolved against keeps
     * the history honest); the moment any transaction exists the history is
     * append-only like every post-launch rate change.
     */
    public function setRate(Merchant $merchant, MerchantUser $actor, int $rateBp): void
    {
        $this->assertEditable($merchant);

        $now = CarbonImmutable::now('UTC');

        DB::transaction(function () use ($merchant, $actor, $rateBp, $now): void {
            TierScheduleService::lockCoverage();

            $this->schedules->assertPricedThrough($rateBp, $now);

            $rows = MerchantRate::query()
                ->where('merchant_id', $merchant->id)
                ->lockForUpdate()
                ->orderByDesc('effective_from')
                ->get();

            $everUsed = Transaction::query()->where('merchant_id', $merchant->id)->exists();

            if (! $everUsed) {
                foreach ($rows as $row) {
                    $row->delete();
                }
            } else {
                $current = $rows->first(fn (MerchantRate $row): bool => $row->effective_from->lessThanOrEqualTo($now)
                    && ($row->effective_to === null || $row->effective_to->isAfter($now)));
                $current?->update(['effective_to' => $now]);
            }

            MerchantRate::query()->create([
                'merchant_id' => $merchant->id,
                'rate_bp' => $rateBp,
                'effective_from' => $now,
                'effective_to' => null,
                'created_by' => $actor->id,
            ]);

            $this->markStep($merchant, 'rate');
            $merchant->save();
        });
    }

    /**
     * Submit for review: re-validates completeness from the actual values
     * (category curated + active, live standing rate, non-empty terms; the
     * logo is optional) and moves the store to pending_review. A resubmit
     * after rejection clears the rejection reason; rejected_at stays as the
     * trail of the last send-back.
     */
    public function submit(Merchant $merchant): Merchant
    {
        $this->assertEditable($merchant);

        $missing = $this->missingRequirements($merchant);

        if ($missing !== []) {
            throw OnboardingException::incomplete($missing);
        }

        $merchant->forceFill([
            'status' => 'pending_review',
            'submitted_at' => CarbonImmutable::now('UTC'),
            'rejected_reason' => null,
        ])->save();

        return $merchant;
    }

    /**
     * Admin approval: pending_review → active, stamped with who and when.
     * The discovery read-model cache is dropped so the new store lists
     * without waiting out the 60s TTL.
     *
     * Completeness is re-validated AT activation, not only at submit:
     * activation is the moment the public-payload contract must hold, and
     * the reviewed state can drift while the store waits in the queue —
     * e.g. its curated category deactivated (the category guard only counts
     * ACTIVE holders). A store that no longer satisfies the submit rules
     * refuses with the same setup_incomplete (422) instead of going live.
     */
    public function approve(Merchant $merchant, AdminUser $admin): Merchant
    {
        DB::transaction(function () use ($merchant, $admin): void {
            $locked = Merchant::query()->whereKey($merchant->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending_review') {
                throw OnboardingException::notPendingReview($locked->status);
            }

            $missing = $this->missingRequirements($locked);

            if ($missing !== []) {
                throw OnboardingException::incomplete($missing);
            }

            $locked->forceFill([
                'status' => 'active',
                'approved_at' => CarbonImmutable::now('UTC'),
                'approved_by' => $admin->id,
            ])->save();
        });

        DiscoveryService::forgetMerchant($merchant);

        return $merchant->refresh();
    }

    /**
     * Admin rejection: pending_review → rejected with a required reason.
     * The merchant keeps panel access, edits the wizard, and resubmits.
     */
    public function reject(Merchant $merchant, string $reason): Merchant
    {
        DB::transaction(function () use ($merchant, $reason): void {
            $locked = Merchant::query()->whereKey($merchant->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending_review') {
                throw OnboardingException::notPendingReview($locked->status);
            }

            $locked->forceFill([
                'status' => 'rejected',
                'rejected_reason' => $reason,
                'rejected_at' => CarbonImmutable::now('UTC'),
            ])->save();
        });

        return $merchant->refresh();
    }

    public function logoUrl(Merchant $merchant): ?string
    {
        return MerchantLogo::url($merchant->slug, $merchant->logo_path);
    }

    /**
     * The standing rate in force right now — for a draft store, the initial
     * rate the wizard wrote.
     */
    public function currentRateBp(Merchant $merchant): ?int
    {
        $now = CarbonImmutable::now('UTC');

        $row = MerchantRate::query()
            ->where('merchant_id', $merchant->id)
            ->where('effective_from', '<=', $now)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $now))
            ->orderByDesc('effective_from')
            ->first(['rate_bp']);

        return $row?->rate_bp;
    }

    /**
     * The submit()/approve() completeness rules, evaluated from the actual
     * column values: curated-and-active category, channel enum, live
     * standing rate, non-empty terms. The logo stays optional.
     *
     * @return list<string>
     */
    private function missingRequirements(Merchant $merchant): array
    {
        $missing = [];

        $categoryOk = $merchant->category !== null
            && StoreCategory::query()->where('slug', $merchant->category)->where('active', true)->exists();

        if (! $categoryOk) {
            $missing[] = 'category';
        }

        if (! in_array($merchant->channel, self::CHANNELS, true)) {
            $missing[] = 'channel';
        }

        if ($this->currentRateBp($merchant) === null) {
            $missing[] = 'rate';
        }

        if (trim((string) $merchant->eligibility_basis) === '') {
            $missing[] = 'terms';
        }

        return $missing;
    }

    private function assertEditable(Merchant $merchant): void
    {
        if (! in_array($merchant->status, self::WIZARD_STATUSES, true)) {
            throw OnboardingException::notEditable($merchant->status);
        }
    }

    private function markStep(Merchant $merchant, string $step): void
    {
        $steps = (array) ($merchant->setup_state ?? []);
        $steps[$step] = true;
        $merchant->setup_state = $steps;
    }
}
