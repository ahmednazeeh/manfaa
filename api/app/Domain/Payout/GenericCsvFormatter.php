<?php

declare(strict_types=1);

namespace App\Domain\Payout;

use App\Domain\Money\Laari;
use App\Models\PayoutBatch;
use App\Models\PayoutItem;

/**
 * Placeholder bulk format until the real BML one is known (§14): plain CSV,
 * one row per item, amounts in decimal MVR without thousands separators so
 * any downstream tool can parse them back losslessly.
 */
final readonly class GenericCsvFormatter implements BankFileFormatter
{
    public function format(PayoutBatch $batch): string
    {
        $lines = ['item_id,account_no,account_name,bank_name,amount_mvr'];

        foreach ($batch->items()->orderBy('id')->get() as $item) {
            /** @var PayoutItem $item */
            $lines[] = implode(',', [
                $item->id,
                $this->field($item->account),
                $this->field($item->account_name),
                $this->field($item->bank),
                str_replace(',', '', Laari::of($item->amount_laari)->formatMvr()),
            ]);
        }

        return implode("\n", $lines)."\n";
    }

    private function field(?string $value): string
    {
        $value ??= '';

        // Formula-injection guard: a cell starting with = + - or @ would be
        // executed by spreadsheet software opening the file. A single-quote
        // prefix makes it inert text.
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
            $value = "'".$value;
        }

        if (preg_match('/[",\n\r]/', $value) === 1) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}
