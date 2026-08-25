<?php

declare(strict_types=1);

namespace App\Domain\Platform;

use App\Models\FeePromotion;
use Carbon\CarbonImmutable;

/**
 * The single `fee_promotions` row as an immutable snapshot — what
 * FeePromotionPolicy caches, and the one place the two "is this promotion
 * actually live?" predicates live.
 *
 * A promotion is LIVE only when it is coherent: switched on, with a fee
 * actually set, and (for the introductory kind) a window of at least one
 * day. FeePromotionsController refuses to save an incoherent state with a
 * 422, so these guards should never fire — they are here because a settings
 * row edited by hand in psql must not be able to price a sale at NULL.
 */
final readonly class FeePromotionSettings
{
    public function __construct(
        public bool $introEnabled,
        public int $introDays,
        public ?int $introFeeBp,
        public ?string $introBannerEn,
        public ?string $introBannerDv,
        public bool $wideEnabled,
        public ?CarbonImmutable $wideFrom,
        public ?CarbonImmutable $wideTo,
        public ?int $wideFeeBp,
        public ?string $wideBannerEn,
        public ?string $wideBannerDv,
    ) {}

    /** Both switches off — the platform as it ships, and as it stands today. */
    public static function off(): self
    {
        return new self(false, 0, null, null, null, false, null, null, null, null, null);
    }

    public static function fromRow(FeePromotion $row): self
    {
        return new self(
            introEnabled: (bool) $row->intro_enabled,
            introDays: (int) $row->intro_days,
            introFeeBp: $row->intro_fee_bp === null ? null : (int) $row->intro_fee_bp,
            introBannerEn: $row->intro_banner_en,
            introBannerDv: $row->intro_banner_dv,
            wideEnabled: (bool) $row->wide_enabled,
            wideFrom: $row->wide_from?->toImmutable()->utc(),
            wideTo: $row->wide_to?->toImmutable()->utc(),
            wideFeeBp: $row->wide_fee_bp === null ? null : (int) $row->wide_fee_bp,
            wideBannerEn: $row->wide_banner_en,
            wideBannerDv: $row->wide_banner_dv,
        );
    }

    /**
     * @param  array<string, mixed>  $cached
     */
    public static function fromCache(array $cached): self
    {
        return new self(
            introEnabled: (bool) $cached['intro_enabled'],
            introDays: (int) $cached['intro_days'],
            introFeeBp: $cached['intro_fee_bp'] === null ? null : (int) $cached['intro_fee_bp'],
            introBannerEn: $cached['intro_banner_en'] === null ? null : (string) $cached['intro_banner_en'],
            introBannerDv: $cached['intro_banner_dv'] === null ? null : (string) $cached['intro_banner_dv'],
            wideEnabled: (bool) $cached['wide_enabled'],
            wideFrom: $cached['wide_from'] === null ? null : CarbonImmutable::parse((string) $cached['wide_from'])->utc(),
            wideTo: $cached['wide_to'] === null ? null : CarbonImmutable::parse((string) $cached['wide_to'])->utc(),
            wideFeeBp: $cached['wide_fee_bp'] === null ? null : (int) $cached['wide_fee_bp'],
            wideBannerEn: $cached['wide_banner_en'] === null ? null : (string) $cached['wide_banner_en'],
            wideBannerDv: $cached['wide_banner_dv'] === null ? null : (string) $cached['wide_banner_dv'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toCache(): array
    {
        return [
            'intro_enabled' => $this->introEnabled,
            'intro_days' => $this->introDays,
            'intro_fee_bp' => $this->introFeeBp,
            'intro_banner_en' => $this->introBannerEn,
            'intro_banner_dv' => $this->introBannerDv,
            'wide_enabled' => $this->wideEnabled,
            'wide_from' => $this->wideFrom?->toIso8601String(),
            'wide_to' => $this->wideTo?->toIso8601String(),
            'wide_fee_bp' => $this->wideFeeBp,
            'wide_banner_en' => $this->wideBannerEn,
            'wide_banner_dv' => $this->wideBannerDv,
        ];
    }

    /**
     * Is the introductory offer ON THE TABLE at all? Not "is this merchant
     * inside it" — that needs their approval date, and lives in the policy.
     */
    public function introLive(): bool
    {
        return $this->introEnabled && $this->introFeeBp !== null && $this->introDays >= 1;
    }

    /** Is the platform-wide window open at this instant? */
    public function wideLiveAt(CarbonImmutable $at): bool
    {
        if (! $this->wideEnabled || $this->wideFeeBp === null || $this->wideFrom === null || $this->wideTo === null) {
            return false;
        }

        // Half-open, like every other window in this codebase: the instant
        // `wide_to` names is the first instant the promotion is OVER.
        return $at >= $this->wideFrom && $at < $this->wideTo;
    }
}
