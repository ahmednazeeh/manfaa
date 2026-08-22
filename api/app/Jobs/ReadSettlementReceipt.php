<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Settlement\ReceiptReader;
use App\Models\SettlementPayment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reads the uploaded receipt's text, so auto-matching can ask whether the
 * bank's payer and reference appear on it.
 *
 * Queued, because OCR on a phone screenshot takes seconds and a merchant
 * pressing "I have paid" must not wait for it. The poll that follows retries
 * for the whole verify window, so text arriving a moment later still gets
 * used — and if it never arrives, matching falls back to the reference and
 * the registered name exactly as before.
 */
final class ReadSettlementReceipt implements ShouldQueue
{
    use Queueable;

    /** One attempt: a receipt that cannot be read will not read better twice. */
    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(private readonly int $paymentId) {}

    public function handle(ReceiptReader $reader): void
    {
        $payment = SettlementPayment::query()->find($this->paymentId);

        if ($payment === null || $payment->slip_path === null || $payment->receipt_text !== null) {
            return;
        }

        $text = $reader->read((string) $payment->slip_path, $payment->slip_mime);

        if ($text === null) {
            return;
        }

        $payment->forceFill(['receipt_text' => $text])->save();
    }
}
