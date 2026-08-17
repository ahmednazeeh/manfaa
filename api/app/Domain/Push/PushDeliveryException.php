<?php

declare(strict_types=1);

namespace App\Domain\Push;

use RuntimeException;

/**
 * Delivery failed in a way worth retrying.
 *
 * `$permanent` separates the two outcomes the queue must treat differently:
 * a provider blip should be retried, while a token the provider has
 * explicitly rejected — uninstalled app, rotated registration — should be
 * DELETED rather than retried forever against a device that no longer
 * exists.
 */
final class PushDeliveryException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $permanent = false)
    {
        parent::__construct($message);
    }

    public static function tokenRejected(string $reason): self
    {
        return new self($reason, permanent: true);
    }
}
