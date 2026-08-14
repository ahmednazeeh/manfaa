<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use InvalidArgumentException;

/**
 * Thrown when an entry line is malformed — negative amounts, or anything
 * other than exactly one of debit/credit greater than zero.
 */
final class InvalidJournalLineException extends InvalidArgumentException {}
