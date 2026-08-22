<?php

declare(strict_types=1);

namespace Manfaa\Cashback\Api;

use Manfaa\Cashback\Support\Options;

/**
 * The store's rate card as the estimate and the line builder see it:
 * standing rate, minimum, and the synced Manfaa categories with their
 * position in the merchant's own order. Cached for an hour; "Sync now" and
 * the `merchant.rate_changed` webhook drop the cache.
 *
 * `pending_decrease` is ignored until it is effective: the order is priced
 * by the server when it is POSTED, not when the cart was shown, and the
 * storefront wording says "estimated" for exactly this reason.
 */
final class RateCard
{
    public const TRANSIENT = 'manfaa_cashback_rate_card';

    private const TTL = HOUR_IN_SECONDS;

    /**
     * @param  list<array{slug:string,name_en:string,name_dv:?string,mode:string,rate_bp:int,position:int,active:bool}>  $categories
     */
    public function __construct(
        public readonly int $rateBp,
        public readonly int $minEligibleLaari,
        public readonly bool $hasCategoryOverrides,
        public readonly array $categories,
        public readonly int $fetchedAt,
    ) {}

    /** The cached card, or null when nothing has been synced yet. */
    public static function cached(): ?self
    {
        $raw = get_transient(self::TRANSIENT);

        return is_array($raw) ? self::fromArray($raw) : self::fromOption();
    }

    /** Fetch from Manfaa and cache. Throws ApiException. */
    public static function sync(Client $client): self
    {
        $rate = $client->get('v1/merchants/me/rate');
        $categories = [];

        if (! empty($rate['has_category_overrides'])) {
            $list = $client->get('v1/merchants/me/product-categories');
            $position = 0;

            foreach ((array) ($list['data'] ?? []) as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $categories[] = [
                    'slug' => (string) ($row['slug'] ?? ''),
                    'name_en' => (string) ($row['name_en'] ?? $row['name'] ?? ''),
                    'name_dv' => isset($row['name_dv']) ? (string) $row['name_dv'] : null,
                    'mode' => (string) ($row['mode'] ?? 'rate'),
                    'rate_bp' => self::bp($row['cashback_rate_percent'] ?? null),
                    'position' => $position++,
                    'active' => true,
                ];
            }
        }

        $card = new self(
            self::bp($rate['cashback_rate_percent'] ?? '0'),
            (int) ($rate['min_eligible_laari'] ?? 0),
            ! empty($rate['has_category_overrides']),
            $categories,
            time(),
        );

        set_transient(self::TRANSIENT, $card->toArray(), self::TTL);
        // A durable copy outlives the transient so the estimate still works
        // when the API is briefly unreachable; it is replaced on every sync.
        update_option('manfaa_cashback_rate_card', $card->toArray(), false);

        return $card;
    }

    public static function forget(): void
    {
        delete_transient(self::TRANSIENT);
    }

    public function stale(): bool
    {
        return time() - $this->fetchedAt > self::TTL;
    }

    /** @return array{slug:string,name_en:string,name_dv:?string,mode:string,rate_bp:int,position:int,active:bool}|null */
    public function category(string $slug): ?array
    {
        foreach ($this->categories as $category) {
            if ($category['slug'] === $slug) {
                return $category;
            }
        }

        return null;
    }

    /** The rate a bucket earns: a category's own, or the standing rate for the null bucket. Excluded = 0. */
    public function bucketRateBp(?string $slug): int
    {
        if ($slug === null) {
            return $this->rateBp;
        }

        $category = $this->category($slug);

        if ($category === null) {
            return $this->rateBp;
        }

        return $category['mode'] === 'excluded' ? 0 : $category['rate_bp'];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'rate_bp' => $this->rateBp,
            'min_eligible_laari' => $this->minEligibleLaari,
            'has_category_overrides' => $this->hasCategoryOverrides,
            'categories' => $this->categories,
            'fetched_at' => $this->fetchedAt,
        ];
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            (int) ($raw['rate_bp'] ?? 0),
            (int) ($raw['min_eligible_laari'] ?? 0),
            (bool) ($raw['has_category_overrides'] ?? false),
            array_values(array_filter((array) ($raw['categories'] ?? []), 'is_array')),
            (int) ($raw['fetched_at'] ?? 0),
        );
    }

    private static function fromOption(): ?self
    {
        $raw = get_option('manfaa_cashback_rate_card');

        return is_array($raw) ? self::fromArray($raw) : null;
    }

    /** "2.50" → 250. Percent strings only — the server never sends floats. */
    public static function bp(mixed $percent): int
    {
        if ($percent === null || $percent === '') {
            return 0;
        }

        $string = is_string($percent) ? $percent : (string) $percent;

        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', trim($string), $m)) {
            return 0;
        }

        $fraction = str_pad($m[2] ?? '0', 2, '0');

        return (int) $m[1] * 100 + (int) $fraction;
    }
}
