<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use RuntimeException;

/**
 * Thrown when a posting names an AccountCode that has no ledger_accounts row.
 * Accounts are seeded by LedgerAccountSeeder; posting never auto-creates them.
 */
final class UnknownLedgerAccountException extends RuntimeException {}
