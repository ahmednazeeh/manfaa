<?php

declare(strict_types=1);

namespace App\Domain\Webhooks;

use App\Models\WebhookEndpoint;

/** A freshly registered endpoint and the one and only showing of its secret. */
final readonly class IssuedEndpoint
{
    public function __construct(
        public WebhookEndpoint $endpoint,
        public string $secret,
    ) {}
}
