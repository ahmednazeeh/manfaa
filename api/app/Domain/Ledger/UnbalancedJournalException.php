<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use DomainException;

/**
 * Thrown when a journal's debits and credits do not sum equal per currency.
 */
final class UnbalancedJournalException extends DomainException {}
