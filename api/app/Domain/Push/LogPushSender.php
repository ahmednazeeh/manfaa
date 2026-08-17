<?php

declare(strict_types=1);

namespace App\Domain\Push;

use Illuminate\Support\Facades\Log;

/**
 * The default. Records what WOULD have been sent and delivers nothing.
 *
 * Deliberately the default rather than a throwing stub: an unconfigured
 * platform must degrade to silence, never to an exception on the money path
 * (NotificationService swallows either, but a log line is the one that leaves
 * evidence an operator can read).
 *
 * Logs the template and the audience, never the device token itself — that
 * is a credential, and a log is not the place to accumulate them.
 */
final class LogPushSender implements PushSender
{
    public function send(string $deviceToken, string $title, string $body, array $data = []): void
    {
        Log::info('Push not delivered — no provider configured.', [
            'title' => $title,
            'data' => $data,
            'device' => substr(hash('sha256', $deviceToken), 0, 12),
        ]);
    }
}
