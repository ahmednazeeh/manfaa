<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Reports\Report;
use App\Domain\Reports\ReportFactory;
use App\Domain\Reports\ReportPeriod;
use App\Domain\Reports\ReportTooLargeException;
use App\Domain\Reports\XlsxWriter;
use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\ReportExport;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The superadmin reporting surface (owner, 2026-08-24): three reports, a
 * period, an optional merchant, a preview on screen and an .xlsx to keep.
 *
 * Two endpoints per report and one behaviour difference between them: the
 * export writes an audit row and the preview does not. A preview is fifty
 * rows on a screen belonging to somebody already logged in; an export puts
 * every customer code and every laari of the money trace into a file that
 * leaves the building. Only one of those is worth a permanent record, and
 * an audit table that also logs idle browsing is an audit table nobody
 * reads.
 *
 * Superadmin only, by middleware on the route group — the reports cross
 * every merchant and every customer at once.
 */
class ReportsController extends Controller
{
    /** Rows of the primary sheet the JSON preview carries. */
    private const int PREVIEW_ROWS = 50;

    public function __construct(private readonly ReportFactory $reports)
    {
        //
    }

    /**
     * GET /api/admin/reports/{report}
     *
     * {summary, preview: {sheet, columns, rows}, row_count, capped}
     */
    public function show(Request $request, string $report): JsonResponse
    {
        [$period, $merchantId] = $this->window($request);

        $built = $this->reports->make($report, $period, $merchantId);

        try {
            ReportTooLargeException::ifOver($built->rowCount());
        } catch (ReportTooLargeException $e) {
            return $this->tooLarge($e);
        }

        $sheet = $built->primarySheet();

        return response()->json([
            'report' => $built->key(),
            'period' => $period->toArray(),
            'merchant_id' => $merchantId,
            'summary' => $built->summary(),
            'preview' => [
                'sheet' => $sheet->title,
                'columns' => $sheet->columnMeta(),
                'rows' => $sheet->previewRows(self::PREVIEW_ROWS),
            ],
            'sheets' => array_map(
                static fn ($each): array => ['title' => $each->title, 'row_count' => $each->count()],
                $built->sheets(),
            ),
            'row_count' => $sheet->count(),
            'capped' => $sheet->count() > self::PREVIEW_ROWS,
        ]);
    }

    /**
     * GET /api/admin/reports/{report}/export — the same figures as a
     * workbook, plus the audit row that says who took them.
     */
    public function export(Request $request, string $report): BinaryFileResponse|JsonResponse
    {
        [$period, $merchantId] = $this->window($request);

        $built = $this->reports->make($report, $period, $merchantId);

        try {
            // The export's own ceiling, lower than the screen's: the
            // workbook is built entirely in memory and PhpSpreadsheet costs
            // ~16.5 MB per thousand rows against a 256M pool. See
            // ReportTooLargeException::MAX_EXPORT_ROWS for the measurements.
            ReportTooLargeException::ifOver($built->rowCount(), ReportTooLargeException::MAX_EXPORT_ROWS);
        } catch (ReportTooLargeException $e) {
            return $this->tooLarge($e);
        }

        // Nothing owns the temp file until deleteFileAfterSend() is armed on
        // a response that is actually returned. Between tempnam() and that
        // moment lives a workbook of customer codes, masked names, bank
        // last-4 and the whole money trace — so every path out of here that
        // is not a handed-off response deletes it. Without the finally, an
        // exception from the audit insert (or a throw inside the writer)
        // left one in /tmp permanently, and nothing reaps them.
        $path = XlsxWriter::forBusinessTime()->write($built->sheets());
        $handedOff = false;

        try {
            $this->audit($request, $built, $period, $merchantId);

            $response = response()
                ->download($path, $this->filename($built, $period, $merchantId), [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'X-Content-Type-Options' => 'nosniff',
                ])
                ->deleteFileAfterSend();

            // Every merchant's sales and every customer code in one file, on
            // a URL with no per-user component. Symfony's BinaryFileResponse
            // is constructed `$public = true` by Laravel's download() and
            // calls setPublic() AFTER applying the header array, so a
            // 'Cache-Control' passed above is overwritten with `public` —
            // which invites shared caches and the browser disk cache to keep
            // the workbook under a key any other admin's request would
            // produce. Set here, after construction, and asserted in
            // XlsxExportTest. Same rule MerchantLogoController uses.
            $response->setPrivate();
            $response->headers->addCacheControlDirective('no-store');

            $handedOff = true;

            return $response;
        } finally {
            if (! $handedOff) {
                @unlink($path);
            }
        }
    }

    /**
     * The window and the filter, validated together.
     *
     * @return array{0: ReportPeriod, 1: int|null}
     */
    private function window(Request $request): array
    {
        $validated = Validator::make($request->query(), [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'merchant_id' => ['sometimes', 'nullable', 'integer', 'exists:merchants,id'],
        ])->after(function ($validator): void {
            $data = $validator->getData();

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $period = ReportPeriod::of((string) $data['from'], (string) $data['to']);

            // A year and a day is the ceiling an interactive export builds
            // in one pass. Queued exports are deferred; when they arrive,
            // this is the rule that relaxes.
            if ($period->days() > ReportPeriod::MAX_DAYS) {
                $validator->errors()->add('to', sprintf(
                    'A report period covers at most %d days — this one covers %d. Narrow the period.',
                    ReportPeriod::MAX_DAYS,
                    $period->days(),
                ));
            }
        })->validate();

        $merchantId = isset($validated['merchant_id']) && $validated['merchant_id'] !== null
            ? (int) $validated['merchant_id']
            : null;

        return [ReportPeriod::of((string) $validated['from'], (string) $validated['to']), $merchantId];
    }

    private function tooLarge(ReportTooLargeException $exception): JsonResponse
    {
        return response()->json([
            'code' => ReportTooLargeException::CODE,
            'message' => $exception->getMessage(),
            'row_count' => $exception->rowCount,
            'limit' => $exception->limit,
        ], 422);
    }

    /**
     * The merchant filter is part of the name, because it is part of what
     * the file IS. Without it an August export for one shop and an August
     * export for all of them both want to be saved as
     * `manfaa-cashback-2026-08-01-2026-08-31.xlsx`, the second lands as
     * `... (1).xlsx`, and nothing on the outside of either file says which
     * is which.
     */
    private function filename(Report $report, ReportPeriod $period, ?int $merchantId): string
    {
        return sprintf(
            'manfaa-%s-%s-%s%s.xlsx',
            $report->key(),
            $period->fromDate(),
            $period->toDate(),
            $merchantId === null ? '' : '-m'.$merchantId,
        );
    }

    /**
     * One row per file that leaves. `row_count` records what the admin
     * actually got, so "they exported August" can be checked against what
     * August held.
     */
    private function audit(Request $request, Report $report, ReportPeriod $period, ?int $merchantId): void
    {
        // Not a ternary with a null fallback: `report_exports.admin_id` is
        // NOT NULL, so the graceful-looking branch could only ever raise a
        // constraint violation. EnsureSuperadmin has already proved this is
        // an AdminUser before the controller runs.
        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        ReportExport::query()->create([
            'admin_id' => $admin->id,
            'report' => $report->key(),
            'period_from' => $period->fromDate(),
            'period_to' => $period->toDate(),
            'merchant_id' => $merchantId,
            'row_count' => $report->rowCount(),
            'created_at' => CarbonImmutable::now('UTC'),
        ]);
    }
}
