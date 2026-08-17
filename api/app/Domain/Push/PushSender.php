<?php

declare(strict_types=1);

namespace App\Domain\Push;

use Illuminate\Container\Attributes\Bind;

/**
 * Delivers one notification to one device.
 *
 * An interface because the provider is a decision with credentials attached
 * and this round must not force it. The default binding writes to the log,
 * so the whole feature — registration, revocation, templating, language,
 * the moments themselves — can ship, be tested and be reviewed before
 * anybody creates a Firebase project.
 */
#[Bind(LogPushSender::class)]
interface PushSender
{
    /**
     * @param  array<string, string>  $data  silent payload for the app to route on
     *
     * @throws PushDeliveryException on a failure worth retrying
     */
    public function send(string $deviceToken, string $title, string $body, array $data = []): void;
}
