<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use InvalidArgumentException;

/**
 * The {report} path segment → the class that answers it. One list, so the
 * route constraint, the validation and the audit row's CHECK constraint can
 * all be checked against the same three names.
 *
 * Deliberately not final: it is the seam a test binds over to hand the
 * controller a report of a size no fixture could build, which is the only
 * way the 50,000-row refusal gets exercised without inserting 50,000 rows.
 */
class ReportFactory
{
    /** @var list<string> */
    public const array KEYS = ['cashback', 'payouts', 'earnings'];

    public function make(string $key, ReportPeriod $period, ?int $merchantId = null): Report
    {
        return match ($key) {
            'cashback' => new CashbackReport($period, $merchantId),
            'payouts' => new PayoutReport($period, $merchantId),
            'earnings' => new EarningsReport($period, $merchantId),
            default => throw new InvalidArgumentException(sprintf('Unknown report [%s].', $key)),
        };
    }
}
