<?php

declare(strict_types=1);

namespace App\Domain\Webhooks;

/**
 * The §9.3 outbound event catalogue — the exact names published in
 * docs/openapi.yaml. Endpoints subscribe to a subset; nothing outside this
 * list is ever a valid subscription or a valid dispatch.
 */
final class WebhookEvents
{
    public const string MERCHANT_RATE_CHANGED = 'merchant.rate_changed';

    public const string MERCHANT_SUSPENDED = 'merchant.suspended';

    public const string MERCHANT_REINSTATED = 'merchant.reinstated';

    public const string TRANSACTION_REVERSED = 'transaction.reversed';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::MERCHANT_RATE_CHANGED,
            self::MERCHANT_SUSPENDED,
            self::MERCHANT_REINSTATED,
            self::TRANSACTION_REVERSED,
        ];
    }
}
