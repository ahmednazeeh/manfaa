<?php

declare(strict_types=1);

namespace App\Domain\Marketplace;

use App\Models\BranchDeliveryRule;

/**
 * What one branch will charge to bring one basket to one island
 * (PLAN-marketplace.md §2.4).
 *
 * Every number a shopper reads about delivery comes from here — the chips on
 * the store page, the subcart's fee line, the "MVR 66 to minimum" progress
 * bar — so the arithmetic exists once rather than in four screens that can
 * drift apart.
 *
 * Immutable, and computed from a BASKET VALUE: the same branch quotes
 * differently as the basket grows, which is the entire point of a
 * free-delivery threshold.
 */
final readonly class DeliveryQuote
{
    private function __construct(
        public bool $delivers,
        public int $feeLaari,
        public bool $feeWaived,
        public ?int $freeDeliveryOverLaari,
        public ?int $orderMinimumLaari,
        public bool $minimumMet,
        public int $shortfallLaari,
        public ?int $etaMin,
        public ?int $etaMax,
        /** The basket this quote was computed against, items only. */
        public int $basisLaari = 0,
    ) {}

    /** This branch does not serve that island at all. */
    public static function unserved(): self
    {
        return new self(
            delivers: false,
            feeLaari: 0,
            feeWaived: false,
            freeDeliveryOverLaari: null,
            orderMinimumLaari: null,
            // Nothing to meet, so nothing is unmet. A caller must read
            // `delivers` first; minimumMet on an unserved island answers a
            // question that was never asked.
            minimumMet: true,
            shortfallLaari: 0,
            etaMin: null,
            etaMax: null,
        );
    }

    /**
     * @param  int  $itemsLaari  the basket for THIS branch, items only —
     *                           delivery is never measured against itself
     */
    public static function for(?BranchDeliveryRule $rule, int $itemsLaari): self
    {
        if ($rule === null) {
            return self::unserved();
        }

        $threshold = $rule->free_delivery_over_laari;

        // "Free delivery over 500" is met AT 500. A threshold a customer can
        // land on exactly and still be charged is a threshold that reads as
        // broken, whatever the wording says.
        $waived = $threshold !== null && $itemsLaari >= $threshold;

        $floor = $rule->order_minimum_laari;
        $met = $floor === null || $itemsLaari >= $floor;

        return new self(
            delivers: true,
            feeLaari: $waived ? 0 : $rule->delivery_fee_laari,
            feeWaived: $waived,
            freeDeliveryOverLaari: $threshold,
            orderMinimumLaari: $floor,
            minimumMet: $met,
            shortfallLaari: $met ? 0 : $floor - $itemsLaari,
            etaMin: $rule->eta_min,
            etaMax: $rule->eta_max,
            basisLaari: $itemsLaari,
        );
    }

    /**
     * How much more would earn free delivery, or null when there is nothing
     * to earn — no threshold, or it is already met. Drives the progress bar
     * in `Market View.png`.
     */
    public function toFreeDeliveryLaari(): ?int
    {
        if ($this->freeDeliveryOverLaari === null || $this->feeWaived) {
            return null;
        }

        return max(0, $this->freeDeliveryOverLaari - $this->basisLaari);
    }

    /**
     * @return array<string, mixed> the wire shape every surface renders
     */
    public function toArray(): array
    {
        return [
            'delivers' => $this->delivers,
            'fee_laari' => $this->feeLaari,
            'fee_waived' => $this->feeWaived,
            'free_delivery_over_laari' => $this->freeDeliveryOverLaari,
            'order_minimum_laari' => $this->orderMinimumLaari,
            'minimum_met' => $this->minimumMet,
            'shortfall_laari' => $this->shortfallLaari,
            'eta_min' => $this->etaMin,
            'eta_max' => $this->etaMax,
            // What the progress bar in Market View.png counts toward.
            'to_free_delivery_laari' => $this->toFreeDeliveryLaari(),
        ];
    }
}
