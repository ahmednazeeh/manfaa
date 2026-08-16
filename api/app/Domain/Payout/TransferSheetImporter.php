<?php

declare(strict_types=1);

namespace App\Domain\Payout;

use App\Models\PayoutBatch;
use App\Models\PayoutItem;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use Throwable;

/**
 * Reads back the transfer sheet the bank filled in and pays the items it
 * names, atomically:
 *
 * - batch: processing → sent when the first outcome lands, then completed
 *   once every item is paid (§6 mapping documented on PayoutBatchState).
 * - a row carrying a Transfer Reference Number pays that item, through the
 *   same ledger path as every other outcome.
 * - a row with the reference left blank is skipped in silence, so the same
 *   sheet can be uploaded again and again as the bank works down it.
 *
 * There is no failure column: a rejected transfer is marked failed by hand on
 * the batch page, because a typo in a free-text column would unlink real
 * transactions.
 *
 * Rows are matched on Idempotency Key, and the key must belong to THIS batch
 * — a sheet from another run is refused whole rather than half applied.
 * Idempotent per item: the same reference on an already-paid item is a no-op,
 * a different one contradicts a transfer already recorded and throws. Any bad
 * row throws inside the transaction, so a rejected upload changes nothing.
 */
final readonly class TransferSheetImporter
{
    public function __construct(private ItemResultService $results) {}

    /**
     * @param  string  $path  the uploaded file on disk — the exported .xlsx,
     *                        or the same sheet saved as CSV
     */
    public function import(PayoutBatch $batch, string $path): PayoutBatch
    {
        $rows = $this->read($path);

        return DB::transaction(function () use ($batch, $rows): PayoutBatch {
            PayoutBatch::query()->whereKey($batch->getKey())->lockForUpdate()->first();
            $batch->refresh();

            if (! $this->results->accepts($batch)) {
                throw InvalidPayoutBatchStateException::import($batch);
            }

            $this->results->begin($batch);

            $items = $batch->items()->get()->keyBy('idempotency_key');

            foreach ($rows as $row) {
                /** @var PayoutItem|null $item */
                $item = $items->get($row['key']);

                if ($item === null) {
                    throw ImportRowException::foreignKey($row['key'], $batch);
                }

                // Nothing filled in for this payee yet: the bank is still
                // working through the sheet, and the next upload of the same
                // file will carry the reference.
                if ($row['reference'] === null) {
                    continue;
                }

                // Re-uploading a sheet whose rows are already applied changes
                // nothing and posts no second journal. A second, different
                // reference on a paid item is another matter: it contradicts
                // a transfer already in the ledger.
                if ($item->state === PayoutItemState::Paid) {
                    if ($item->bank_reference === $row['reference']) {
                        continue;
                    }

                    throw ImportRowException::alreadyResolved($item);
                }

                if ($item->state !== PayoutItemState::Sent) {
                    throw ImportRowException::alreadyResolved($item);
                }

                $this->results->applyPaid($item, $row['reference']);
            }

            $this->results->conclude($batch);

            return $batch->refresh();
        });
    }

    /**
     * @return list<array{key: string, reference: ?string}>
     */
    private function read(string $path): array
    {
        try {
            $reader = IOFactory::createReaderForFile($path, [IOFactory::READER_XLSX, IOFactory::READER_CSV]);
            $reader->setReadDataOnly(true);
            $grid = $reader->load($path)->getActiveSheet()->toArray(null, false, false, false);
        } catch (Throwable) {
            throw ImportRowException::unreadable();
        }

        [$headerRow, $keyColumn, $referenceColumn] = $this->locateHeadings($grid);

        $rows = [];

        foreach (array_slice($grid, $headerRow + 1) as $cells) {
            $key = $this->text($cells[$keyColumn] ?? null);

            // Excel pads a saved sheet with empty rows below the data, and
            // finance sometimes leaves a total under the table — neither is
            // a claim about a transfer.
            if ($key === '') {
                continue;
            }

            $reference = $this->text($cells[$referenceColumn] ?? null);

            $rows[] = [
                'key' => $key,
                'reference' => $reference === '' ? null : $reference,
            ];
        }

        return $rows;
    }

    /**
     * The header is wherever the two columns we read sit — finance opens the
     * sheet in Excel and a title line above the table survives the round
     * trip often enough to be worth tolerating.
     *
     * @param  array<int, array<int, mixed>>  $grid
     * @return array{int, int, int} row index, key column, reference column
     */
    private function locateHeadings(array $grid): array
    {
        foreach ($grid as $index => $cells) {
            $headings = [];

            foreach ($cells as $column => $value) {
                $headings[mb_strtolower($this->text($value))] = $column;
            }

            $key = $headings[mb_strtolower(TransferSheetExporter::KEY_HEADING)] ?? null;
            $reference = $headings[mb_strtolower(TransferSheetExporter::REFERENCE_HEADING)] ?? null;

            if ($key !== null && $reference !== null) {
                return [$index, $key, $reference];
            }
        }

        throw ImportRowException::missingHeadings();
    }

    private function text(mixed $value): string
    {
        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }

        // A reference typed as digits comes back from Excel as a float;
        // printing it as an integer keeps 900123456 out of scientific
        // notation, which would never match what the bank sent.
        if (is_float($value) && $value === floor($value) && abs($value) < 1e15) {
            return sprintf('%.0F', $value);
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
