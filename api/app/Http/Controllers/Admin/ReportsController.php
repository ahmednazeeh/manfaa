<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Reports\Report;
use App\Domain\Reports\ReportFactory;
use App\Domain\Reports\ReportOptions;
use App\Domain\Reports\ReportPeriod;
use App\Domain\Reports\ReportTooLargeException;
use App\Domain\Reports\XlsxWriter;
use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\ReportExport;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The superadmin reporting surface (owner, 2026-08-24): three reports, a
 * period, an optional merchant, a preview on screen and an .xlsx to keep.
 *
 * Two endpoints per report and TWO behaviour differences between them.
 *
 * The export writes an audit row and the preview does not. A preview is
 * fifty rows on a screen belonging to somebody already logged in; an export
 * puts every customer code and every laari of the money trace into a file
 * that leaves the building. Only one of those is worth a permanent record,
 * and an audit table that also logs idle browsing is an audit table nobody
 * reads.
 *
 * The export is also the UNMASKED render (owner, 2026-08-24). Same rows,
 * same figures, same period — but full customer names, whole bank account
 * numbers and full account names, because the workbook exists to be
 * reconciled against a bank statement and filed for tax. The preview stays
 * masked: it is a glance view, and a JSON body is a much easier thing to
 * leave lying around than a downloaded file. Masking::Full is reachable
 * only through Report::forExport(), and only from export() below.
 *
 * Both endpoints take `include_reversed` and both default it to false, so
 * the preview always describes the file the export will produce.
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
        [$period, $merchantId, $options] = $this->window($request);

        // MASKED, by construction. `make()` never hands back an unmasked
        // report — forExport() is the only door — and previewPayload()
        // refuses to serialise one even if some future caller finds
        // another. Two independent guards, because the failure they prevent
        // is real customer names and whole bank account numbers in a JSON
        // body that a browser caches.
        $built = $this->reports->make($report, $period, $merchantId, $options);

        try {
            ReportTooLargeException::ifOver($built->rowCount());
        } catch (ReportTooLargeException $e) {
            return $this->tooLarge($e);
        }

        $preview = $built->previewPayload(self::PREVIEW_ROWS);

        return response()->json([
            'report' => $built->key(),
            'period' => $period->toArray(),
            'merchant_id' => $merchantId,
            // Echoed back so the panel's toggle can prove it took effect,
            // and so a preview that says "reversed excluded" is describing
            // the same rows the export will contain.
            'include_reversed' => $built->includeReversed(),
            // Whether the setting can change THIS report at all. False on
            // payouts (paid is terminal) and on earnings (ledger-derived, so
            // reversal journals always stay), and the panel needs it from
            // the server rather than from a list of report names in the
            // browser: the screen must not label a report "reversed rows
            // included" when the flag did nothing to it.
            'reversed_rows_apply' => $built->reversedRowsApply(),
            'summary' => $built->summary(),
            // The workbook's own header block, verbatim — the period, the
            // filters, the reversed-rows setting and the money-direction
            // glossary. The panel shows the reader what the file will say
            // before they download it; the block itself is never among the
            // preview ROWS, which stay positional and column-aligned.
            'header' => $built->headerBlock()?->toArray(),
            'preview' => $preview,
            'sheets' => array_map(
                static fn ($each): array => ['title' => $each->title, 'row_count' => $each->count()],
                $built->sheets(),
            ),
            'row_count' => $built->primarySheet()->count(),
            'capped' => $built->primarySheet()->count() > self::PREVIEW_ROWS,
        ]);
    }

    /**
     * GET /api/admin/reports/{report}/export — the same figures as a
     * workbook, plus the audit row that says who took them.
     */
    public function export(Request $request, string $report): BinaryFileResponse|JsonResponse
    {
        [$period, $merchantId, $options] = $this->window($request);

        // THE ONE UNMASKED RENDER (owner, 2026-08-24). The .xlsx carries
        // full customer names, whole bank account numbers and full account
        // names, because it is a superadmin-only, audited artefact whose
        // job is to be reconciled line by line against a bank statement and
        // filed for tax — and "****4821" reconciles against nothing.
        //
        // forExport() returns a NEW report rather than flipping a flag on
        // this one, so no cached masked sheet can leak into it and no
        // later caller can find this instance in the unmasked state.
        $built = $this->reports->make($report, $period, $merchantId, $options)->forExport();

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
        // moment lives a workbook of customer codes, FULL customer names,
        // WHOLE bank account numbers and the entire money trace — so every
        // path out of here that is not a handed-off response deletes it.
        // Without the finally, an exception from the audit insert (or a
        // throw inside the writer) left one in /tmp permanently, and nothing
        // reaps them. The file got more sensitive when masking came off it,
        // so the finally matters more than it did.
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
     * The window, the filter and the render options, validated together.
     *
     * `include_reversed` arrives in a QUERY STRING, where a boolean has no
     * canonical spelling: a browser fetch built with URLSearchParams sends
     * "true", a hand-typed URL says "1", and Laravel's own `boolean` rule
     * accepts the second and rejects the first. Both are the same intent,
     * and a report toggle that 422s depending on which client built the URL
     * is a bug waiting for the first person who does not use the panel.
     *
     * So the rule and the parse are THE SAME FUNCTION (boolish). Validation
     * accepts exactly the spellings the parse understands, and neither can
     * drift into accepting a value the other reads differently — which is
     * the failure that would silently flip a report's contents.
     *
     * ABSENT MEANS FALSE, on both endpoints: a caller that omits it gets
     * reversed rows excluded, which is the default the owner asked for and
     * the direction a report is safe to be wrong in.
     *
     * @return array{0: ReportPeriod, 1: int|null, 2: ReportOptions}
     */
    private function window(Request $request): array
    {
        $validated = Validator::make($request->query(), [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'merchant_id' => ['sometimes', 'nullable', 'integer', 'exists:merchants,id'],
            'include_reversed' => ['sometimes', 'nullable', function (string $attribute, mixed $value, Closure $fail): void {
                if (self::boolish($value) === null) {
                    $fail('The include reversed field must be true or false.');
                }
            }],
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

        return [
            ReportPeriod::of((string) $validated['from'], (string) $validated['to']),
            $merchantId,
            ReportOptions::of(self::boolish($validated['include_reversed'] ?? null) ?? false),
        ];
    }

    /**
     * A query-string boolean, or null when the value is not one.
     *
     * Absent and empty (`?include_reversed=`, which is what an unset form
     * field serialises to) both mean the default rather than an error: the
     * caller expressed no preference, and refusing the request would make
     * the panel's own empty state a 422.
     */
    private static function boolish(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        if (is_bool($value)) {
            return $value;
        }

        // FILTER_NULL_ON_FAILURE is what turns this from a coercion into a
        // decision: "perhaps" becomes null and is refused, rather than
        // becoming false and quietly producing the wrong report.
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
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
     *
     * The reversed-rows choice is in the name for exactly the same reason,
     * and it is the more dangerous of the two: the same shop, the same
     * August, exported both ways, produces two files with DIFFERENT totals.
     * A reader who cannot tell them apart from the filename will eventually
     * send the wrong one to an accountant. Only the non-default is marked —
     * the ordinary export keeps the name it has always had.
     *
     * But only where the choice CHANGED something. On payouts and earnings
     * the flag is inert by construction, so both spellings of the request
     * produce a byte-identical workbook; suffixing one of them would name
     * two identical files differently and advertise a difference in totals
     * that does not exist — the same lie in the other direction. Hence
     * effectiveReversed(), which both this and the audit row read.
     */
    private function filename(Report $report, ReportPeriod $period, ?int $merchantId): string
    {
        return sprintf(
            'manfaa-%s-%s-%s%s%s.xlsx',
            $report->key(),
            $period->fromDate(),
            $period->toDate(),
            $merchantId === null ? '' : '-m'.$merchantId,
            $this->effectiveReversed($report) ? '-with-reversed' : '',
        );
    }

    /**
     * Whether reversed rows are in THIS file — the request's flag AND the
     * report's ability to act on it.
     *
     * `include_reversed=1` on an earnings export is not a lie the admin
     * told; it is a setting that report has nothing to apply. Recording it
     * as true would put a difference on the audit row that no two files
     * have, and the whole point of that column is telling two files apart a
     * year later.
     */
    private function effectiveReversed(Report $report): bool
    {
        return $report->includeReversed() && $report->reversedRowsApply();
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
            // Read off the REPORT, not off the request, and only where the
            // report could act on it. This records what the workbook
            // actually contains rather than what the query string asked
            // for — so the same period exported both ways leaves two rows
            // that can be told apart, and a report the flag cannot touch
            // does not claim a difference between two identical files.
            'include_reversed' => $this->effectiveReversed($report),
            'row_count' => $report->rowCount(),
            'created_at' => CarbonImmutable::now('UTC'),
        ]);
    }
}
