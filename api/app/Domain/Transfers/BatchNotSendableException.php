<?php

declare(strict_types=1);

namespace App\Domain\Transfers;

use RuntimeException;

/** The batch is not in a state where a bank pass makes sense. */
final class BatchNotSendableException extends RuntimeException {}
