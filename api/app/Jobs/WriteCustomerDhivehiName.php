<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Customers\DhivehiNameWriter;
use App\Models\Customer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fills in a customer's Thaana name after they register (owner, 2026-08-21).
 *
 * Queued, so signing up never waits on an API call to Anthropic and never
 * fails because of one. `$tries = 2` because the failure worth retrying is a
 * blip on the wire; a model that answered something unusable will answer the
 * same way again, and {@see DhivehiNameWriter} returns null for that rather
 * than throwing.
 */
final class WriteCustomerDhivehiName implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(private readonly int $customerId) {}

    public function handle(DhivehiNameWriter $writer): void
    {
        $customer = Customer::query()->find($this->customerId);

        if ($customer === null || $customer->name_dv !== null) {
            // Gone, or a person already typed their own — never overwrite
            // what the customer corrected in Profile.
            return;
        }

        $written = $writer->write((string) $customer->name);

        if ($written === null) {
            return;
        }

        // Re-read under the write: a correction may have landed while the
        // model was thinking, and the human wins.
        Customer::query()
            ->whereKey($this->customerId)
            ->whereNull('name_dv')
            ->update(['name_dv' => $written]);
    }
}
