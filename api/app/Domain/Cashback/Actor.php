<?php

declare(strict_types=1);

namespace App\Domain\Cashback;

use InvalidArgumentException;

/**
 * Who performed a state transition. Recorded verbatim on every
 * transaction_events row — no transition happens without one.
 */
final readonly class Actor
{
    private const array TYPES = ['system', 'admin', 'merchant_user', 'customer', 'pos'];

    public function __construct(
        public string $actorType,
        public ?int $actorId = null,
    ) {
        if (! in_array($actorType, self::TYPES, true)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid actor type.', $actorType));
        }
    }

    public static function system(): self
    {
        return new self('system');
    }

    public static function admin(int $adminUserId): self
    {
        return new self('admin', $adminUserId);
    }

    public static function merchantUser(int $merchantUserId): self
    {
        return new self('merchant_user', $merchantUserId);
    }

    public static function customer(int $customerId): self
    {
        return new self('customer', $customerId);
    }

    public static function pos(?int $apiCredentialId = null): self
    {
        return new self('pos', $apiCredentialId);
    }
}
