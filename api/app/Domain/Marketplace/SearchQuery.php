<?php

declare(strict_types=1);

namespace App\Domain\Marketplace;

/**
 * What a shopper actually asked for, pulled out of what they typed
 * (`AI Product Search.png`).
 *
 * "best jasmine rice under MVR 100" is three statements: the goods, a
 * ceiling, and a preference for quality. A marketplace that treats the whole
 * string as a name match finds nothing and looks broken.
 *
 * This is the DETERMINISTIC pass. It runs always, costs nothing, and is
 * honest about what it understood — the chips on screen are exactly these
 * fields. A Claude parser can layer over it for the phrasings a regex will
 * never catch (§6), but the floor should not depend on a network call.
 */
final readonly class SearchQuery
{
    private function __construct(
        /** The words left after the qualifiers are removed — the goods. */
        public string $terms,
        public ?int $maxPriceLaari,
        public ?float $minRating,
        public bool $fastDelivery,
        public bool $bestValue,
        /** @var list<array{key: string, label: string}> */
        public array $facets,
    ) {}

    public static function parse(string $raw): self
    {
        $text = trim($raw);
        $lower = mb_strtolower($text);
        $facets = [];

        $maxPrice = null;
        // "under MVR 100", "under 100", "below mvr 100", "less than 100"
        if (preg_match('/\b(?:under|below|less than|upto|up to|max)\s*(?:mvr|rf)?\s*([0-9]+(?:\.[0-9]{1,2})?)/i', $lower, $m)) {
            $maxPrice = (int) round(((float) $m[1]) * 100);
            $facets[] = ['key' => 'max_price', 'label' => 'Under MVR '.rtrim(rtrim(number_format((float) $m[1], 2), '0'), '.')];
            $lower = str_replace($m[0], ' ', $lower);
        }

        $minRating = null;
        if (preg_match('/\b([1-5](?:\.[0-9])?)\s*(?:star|stars|\*)\b/i', $lower, $m)) {
            $minRating = (float) $m[1];
            $facets[] = ['key' => 'min_rating', 'label' => $m[1].'★ & above'];
            $lower = str_replace($m[0], ' ', $lower);
        } elseif (str_contains($lower, 'high rating') || str_contains($lower, 'best rated') || str_contains($lower, 'top rated')) {
            $minRating = 4.0;
            $facets[] = ['key' => 'min_rating', 'label' => 'High rating'];
            $lower = str_replace(['high rating', 'best rated', 'top rated'], ' ', $lower);
        }

        $fast = false;
        foreach (['fast delivery', 'quick delivery', 'fastest', 'asap', 'quickest'] as $phrase) {
            if (str_contains($lower, $phrase)) {
                $fast = true;
                $lower = str_replace($phrase, ' ', $lower);
            }
        }
        if ($fast) {
            $facets[] = ['key' => 'fast_delivery', 'label' => 'Fast delivery'];
        }

        $best = false;
        foreach (['best value', 'cheapest', 'best price', 'lowest price'] as $phrase) {
            if (str_contains($lower, $phrase)) {
                $best = true;
                $lower = str_replace($phrase, ' ', $lower);
            }
        }
        if ($best) {
            $facets[] = ['key' => 'best_value', 'label' => 'Best value'];
        }

        // Filler that only ever precedes the goods.
        $lower = preg_replace('/\b(best|good|nice|show me|find me|find|i want|looking for|some|any|the|a|an|for)\b/i', ' ', $lower) ?? $lower;
        $terms = trim(preg_replace('/\s+/', ' ', $lower) ?? '');

        if ($terms !== '') {
            // First chip is what they are shopping for, as the ref shows it.
            array_unshift($facets, [
                'key' => 'terms',
                'label' => mb_convert_case($terms, MB_CASE_TITLE),
            ]);
        }

        return new self($terms, $maxPrice, $minRating, $fast, $best, $facets);
    }

    /** A sentence for the AI card, describing what we understood. */
    public function summary(int $found): string
    {
        if ($found === 0) {
            return $this->terms === ''
                ? 'Type what you are after — a product, a brand, a size.'
                : 'Nothing matches “'.$this->terms.'” in the shops open to you yet.';
        }

        $parts = ["I found $found ".($found === 1 ? 'option' : 'options')];

        if ($this->terms !== '') {
            $parts[] = 'for '.$this->terms;
        }

        if ($this->maxPriceLaari !== null) {
            $parts[] = 'under MVR '.number_format($this->maxPriceLaari / 100, 2);
        }

        return implode(' ', $parts).' across every Manfaa store.';
    }
}
