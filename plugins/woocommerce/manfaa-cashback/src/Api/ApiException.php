<?php

declare(strict_types=1);

namespace Manfaa\Cashback\Api;

use RuntimeException;

/**
 * Every non-2xx answer from Manfaa, with the machine code the guide says to
 * match on. `transport` is true when no HTTP answer arrived at all.
 */
final class ApiException extends RuntimeException
{
    /** @param array<string, mixed> $body */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $body = [],
        public readonly ?int $retryAfter = null,
        public readonly bool $transport = false,
    ) {
        parent::__construct($message);
    }

    public static function transport(string $message): self
    {
        return new self(0, 'transport', $message, transport: true);
    }

    /** Worth trying again later with the same idempotency key. */
    public function retryable(): bool
    {
        if ($this->transport || $this->status >= 500 || $this->status === 429) {
            return true;
        }

        return $this->status === 409 && $this->errorCode === 'idempotency_key_in_flight';
    }

    /** The token is dead or the store cannot trade: stop scheduling, say so. */
    public function disconnects(): bool
    {
        return $this->status === 401
            || ($this->status === 403 && $this->errorCode === 'forbidden_ability')
            || $this->errorCode === 'no_effective_rate';
    }

    /** @return mixed */
    public function meta(string $key): mixed
    {
        return $this->body['error']['meta'][$key] ?? null;
    }
}
