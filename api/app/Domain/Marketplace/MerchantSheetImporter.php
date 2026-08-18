<?php

declare(strict_types=1);

namespace App\Domain\Marketplace;

use App\Models\MerchantPayoutBatch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * The filled sheet coming back from finance.
 *
 * A row with a reference in it is a transfer that HAPPENED; a row with the
 * box left empty is one that did not. Nothing else is inferred — this is a
 * record of what a bank did, not a place to guess.
 *
 * Matched on the payout key, which is the same `internal_ref` the bank API
 * would deduplicate on. One string identifies a transfer everywhere: our
 * table, the bank's, and the paper in between.
 */
final readonly class MerchantSheetImporter
{
    /**
     * @return array{matched: int, paid: int, unmatched: list<string>}
     */
    public function import(MerchantPayoutBatch $batch, string $path): array
    {
        $rows = $this->read($path);

        return DB::transaction(function () use ($batch, $rows): array {
            $locked = MerchantPayoutBatch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();

            // `completed` is accepted on purpose: re-uploading a sheet is
            // something people do, and refusing it teaches them to work
            // around us. Safe because an item already `sent` is skipped —
            // idempotence comes from the row, not from the door.
            if (! in_array($locked->state, ['approved', 'processing', 'completed'], true)) {
                throw new \RuntimeException('Approve the batch before importing results.');
            }

            $matched = 0;
            $paid = 0;
            $unmatched = [];

            foreach ($rows as $row) {
                $item = $locked->items()->where('internal_ref', $row['key'])->first();

                if ($item === null) {
                    // A key we did not issue. Reported, never guessed at —
                    // a mistyped row must not silently mark the wrong shop
                    // paid.
                    $unmatched[] = $row['key'];

                    continue;
                }

                $matched++;

                if ($row['reference'] === null) {
                    continue;
                }

                if ($item->state === 'sent') {
                    // Already recorded. Re-importing the same sheet is a
                    // thing people do, and it must not double anything.
                    continue;
                }

                $item->forceFill([
                    'state' => 'sent',
                    'trx_id' => $row['reference'],
                    'paid_at' => CarbonImmutable::now(),
                    'error_code' => null,
                    'failure_reason' => null,
                ])->save();

                $paid++;
            }

            $locked->forceFill([
                'state' => $locked->items()->where('state', '!=', 'sent')->exists()
                    ? 'processing'
                    : 'completed',
            ])->save();

            return ['matched' => $matched, 'paid' => $paid, 'unmatched' => $unmatched];
        });
    }

    /**
     * @return list<array{key: string, reference: string|null}>
     */
    private function read(string $path): array
    {
        try {
            $reader = IOFactory::createReaderForFile($path, [IOFactory::READER_XLSX, IOFactory::READER_CSV]);
            $reader->setReadDataOnly(true);
            $grid = $reader->load($path)->getActiveSheet()->toArray(null, false, false, false);
        } catch (Throwable) {
            throw new \RuntimeException('That file could not be read as a transfer sheet.');
        }

        [$headerRow, $keyColumn, $referenceColumn] = $this->locateHeadings($grid);

        $rows = [];

        foreach (array_slice($grid, $headerRow + 1) as $cells) {
            $key = trim((string) ($cells[$keyColumn] ?? ''));

            // Excel pads a saved sheet with empty rows, and finance
            // sometimes leaves a total under the table. Neither is a claim
            // about a transfer.
            if ($key === '') {
                continue;
            }

            $reference = trim((string) ($cells[$referenceColumn] ?? ''));

            $rows[] = ['key' => $key, 'reference' => $reference === '' ? null : $reference];
        }

        return $rows;
    }

    /**
     * Find the two columns that matter BY NAME rather than by position, so a
     * sheet that came back with a column inserted still imports.
     *
     * @param  array<int, array<int, mixed>>  $grid
     * @return array{int, int, int}
     */
    private function locateHeadings(array $grid): array
    {
        foreach ($grid as $rowIndex => $cells) {
            $key = null;
            $reference = null;

            foreach ($cells as $columnIndex => $value) {
                $heading = trim((string) $value);

                if (strcasecmp($heading, MerchantTransferSheet::KEY_HEADING) === 0) {
                    $key = $columnIndex;
                }

                if (strcasecmp($heading, MerchantTransferSheet::REFERENCE_HEADING) === 0) {
                    $reference = $columnIndex;
                }
            }

            if ($key !== null && $reference !== null) {
                return [$rowIndex, $key, $reference];
            }
        }

        throw new \RuntimeException('That sheet has no Payout Key and Transfer Reference Number columns.');
    }
}
