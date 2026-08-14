<?php

declare(strict_types=1);

namespace App\Domain\Standing;

use App\Models\MerchantNotice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Writes one merchant_notices row and mirrors it to the default log channel.
 * The DB row is the evidence (§7 — "every step is a scheduled job writing a
 * recorded outcome"); the log line is the delivery channel in Phase 1.
 */
final class NoticeRecorder
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(int $merchantId, string $type, array $payload = []): MerchantNotice
    {
        $notice = MerchantNotice::query()->create([
            'merchant_id' => $merchantId,
            'type' => $type,
            'channel' => 'log',
            'payload' => $payload === [] ? null : $payload,
            'sent_at' => CarbonImmutable::now('UTC'),
        ]);

        Log::info(sprintf('Merchant notice %s sent to merchant #%d.', $type, $merchantId), $payload);

        return $notice;
    }
}
