<?php

declare(strict_types=1);

namespace App\Domain\Payout;

/**
 * One customer's payable position at a cutoff: the exact transactions that
 * make it up and their summed stored cashback integers.
 */
final readonly class EligibleCustomer
{
    /**
     * @param  list<int>  $transactionIds
     */
    public function __construct(
        public int $customerId,
        public int $amountLaari,
        public array $transactionIds,
    ) {}
}
