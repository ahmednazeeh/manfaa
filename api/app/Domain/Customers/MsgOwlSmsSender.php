<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * MsgOwl SMS delivery (https://docs.msgowl.com/). Contract mirrored from the
 * proven IsleBooks integration on this host: POST {rest_base}/messages with
 * an AccessKey header and {recipients, body, sender_id} JSON.
 *
 * Selected over LogSmsSender by AppServiceProvider whenever
 * services.msgowl.key is configured. The sender id is currently borrowed
 * from IsleBooks until Manfaa's own id is registered with MsgOwl.
 */
final class MsgOwlSmsSender implements SmsSender
{
    public function send(string $phone, string $message): void
    {
        $config = config('services.msgowl');

        $payload = [
            'recipients' => [$phone],
            'body' => $message,
        ];

        if (($config['sender_id'] ?? '') !== '') {
            $payload['sender_id'] = $config['sender_id'];
        }

        $response = Http::withHeaders([
            'Authorization' => 'AccessKey '.$config['key'],
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])
            ->timeout((int) ($config['timeout'] ?? 15))
            ->post(rtrim($config['base_url'] ?? 'https://rest.msgowl.com', '/').'/messages', $payload);

        if ($response->failed()) {
            // Mask the recipient; never log message content (it carries the OTP).
            Log::error('MsgOwl SMS send failed', [
                'phone' => substr($phone, 0, 4).'****'.substr($phone, -3),
                'status' => $response->status(),
            ]);

            throw new RuntimeException('SMS provider rejected the message.');
        }
    }
}
