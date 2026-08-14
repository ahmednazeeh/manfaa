<?php

declare(strict_types=1);

namespace App\Domain\Payout;

use App\Models\PayoutBatch;

/**
 * Renders an approved batch as the bank's bulk transfer file. The real BML
 * bulk format is a §14 open item — when it lands, implement it as another
 * formatter and swap the binding; BankFileExporter never changes.
 */
interface BankFileFormatter
{
    public function format(PayoutBatch $batch): string;
}
