<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Settlement\ReceiptReader;
use App\Models\WalletTopUp;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reads the uploaded top-up slip's text, so auto-matching can ask whether
 * the bank's payer and reference appear on it — the top-up twin of
 * {@see ReadSettlementReceipt}, same reader, same one attempt.
 */
final class ReadWalletTopUpReceipt implements ShouldQueue
{
    use Queueable;

    /** One attempt: a receipt that cannot be read will not read better twice. */
    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(private readonly int $topUpId) {}

    public function handle(ReceiptReader $reader): void
    {
        $topUp = WalletTopUp::query()->find($this->topUpId);

        if ($topUp === null || $topUp->slip_path === null || $topUp->receipt_text !== null) {
            return;
        }

        $text = $reader->read((string) $topUp->slip_path, $topUp->slip_mime);

        if ($text === null) {
            return;
        }

        $topUp->forceFill(['receipt_text' => $text])->save();
    }
}
