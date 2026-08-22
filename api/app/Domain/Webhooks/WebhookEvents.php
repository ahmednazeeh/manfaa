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
     * Sent only by "Send test" on a merchant endpoint (owner, 2026-08-22).
     * Deliberately NOT in {@see all()}: nobody can subscribe to it and the
     * dispatcher never emits it — it proves a URL and a signature, nothing
     * else. Receivers should acknowledge it with a 2xx and do no work.
     */
    public const string TEST = 'webhook.test';

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
