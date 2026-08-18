<?php

namespace App\Models;

use Database\Factories\MerchantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Laravel\Sanctum\HasApiTokens;

class Merchant extends Model
{
    /** @use HasFactory<MerchantFactory> */
    use HasApiTokens, HasFactory;

    protected $guarded = [];

    /**
     * The support phone is always MATERIALISED (owner decision 2026-08-17):
     * "same as the contact number" saves the contact number itself, and the
     * edit UIs derive the tick by comparing the two fields. The previous
     * convention — NULL means "same", read models fall back at display time
     * — left the customer app showing no number at all, because a NULL
     * survives every payload that forgets to fall back.
     *
     * Two rules, on every save regardless of which controller wrote:
     *  - a blank support phone copies the contact phone;
     *  - a support phone that matched the contact phone FOLLOWS it when the
     *    contact changes (unless this save sets support explicitly) — so
     *    the stored copy cannot go stale, which was the argument for the
     *    NULL convention in the first place.
     */
    protected static function booted(): void
    {
        static::saving(function (Merchant $merchant): void {
            if (blank($merchant->contact_phone)) {
                return;
            }

            $wasSame = blank($merchant->getOriginal('support_phone'))
                || $merchant->getOriginal('support_phone') === $merchant->getOriginal('contact_phone');

            if (blank($merchant->support_phone)
                || ($wasSame && $merchant->isDirty('contact_phone') && ! $merchant->isDirty('support_phone'))) {
                $merchant->support_phone = $merchant->contact_phone;
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'validation_window_days' => 'integer',
            'min_eligible_laari' => 'integer',
            'setup_state' => 'array',
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'unpublished_at' => 'immutable_datetime',
            'unpublish_notified_at' => 'immutable_datetime',
            'republish_notified_at' => 'immutable_datetime',
        ];
    }

    /**
     * Publicly listed RIGHT NOW: approved, still trading, and not taken down
     * by its own owner. The two halves are independent — an unpublished
     * store is not suspended, and a suspended store is not unpublished — so
     * every public query must ask both. This is the one place that spells
     * the answer out.
     */
    public function isPublished(): bool
    {
        return $this->status === 'active' && $this->unpublished_at === null;
    }

    /**
     * The lifecycle status in words (PLAN §13b task #22 — no raw snake_case
     * in rendered output).
     *
     * `status` is a plain string column, not an enum, because the check
     * constraint is the authority (see the onboarding-lifecycle migration).
     * Refusal messages that name the status are echoed verbatim by both
     * panels, so `pending_review` would otherwise reach a shopkeeper's
     * screen. An unrecognised value degrades to neutral prose rather than
     * printing itself — the one thing this must never do.
     */
    public static function statusLabel(?string $status): string
    {
        return match ($status) {
            'draft' => 'still being set up',
            'pending_review' => 'awaiting review',
            'rejected' => 'not approved',
            'active' => 'active',
            'suspended' => 'suspended',
            'closed' => 'closed',
            default => 'not active',
        };
    }

    public function branches(): HasMany
    {
        return $this->hasMany(MerchantBranch::class);
    }

    /** Marketplace enrolment — absent for a store that never opted in. */
    public function marketplace(): HasOne
    {
        return $this->hasOne(MerchantMarketplaceProfile::class);
    }

    public function kybDocuments(): HasMany
    {
        return $this->hasMany(MerchantKybDocument::class);
    }

    /** Product DEFINITIONS. What each branch charges lives on the listing. */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Selling on the marketplace right now.
     *
     * Deliberately asks BOTH questions: the store must be trading as a
     * merchant at all (active, not suspended, not paused by its own owner)
     * AND enrolled and approved as a vendor. Neither implies the other.
     */
    public function sellsOnMarketplace(): bool
    {
        return $this->isPublished() && ($this->marketplace?->isLive() ?? false);
    }

    public function users(): HasMany
    {
        return $this->hasMany(MerchantUser::class);
    }

    public function rates(): HasMany
    {
        return $this->hasMany(MerchantRate::class);
    }

    public function promotions(): HasMany
    {
        return $this->hasMany(Promotion::class);
    }

    public function productCategories(): HasMany
    {
        return $this->hasMany(MerchantProductCategory::class);
    }

    public function apiCredentials(): HasMany
    {
        return $this->hasMany(ApiCredential::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(MerchantWallet::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'approved_by');
    }
}
