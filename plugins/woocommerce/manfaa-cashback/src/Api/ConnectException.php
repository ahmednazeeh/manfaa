<?php

declare(strict_types=1);

namespace Manfaa\Cashback\Api;

use RuntimeException;

/** A connect attempt that failed for a reason the shopkeeper can read. */
final class ConnectException extends RuntimeException {}
