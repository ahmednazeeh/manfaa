<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Dashboard\AdminDashboard;
use App\Domain\Dashboard\AttentionQueues;
use App\Domain\Dashboard\DashboardPeriod;
use App\Domain\Reports\ReportPeriod;
use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * GET /api/admin/dashboard — the console's landing page, in one request.
 * GET /api/admin/dashboard/attention — its first section on its own, for the
 * nav badges (see attention() below).
 *
 * ?from=YYYY-MM-DD&to=YYYY-MM-DD, both optional and both business-timezone
 * dates (§13, Indian/Maldives — never the browser's, never UTC). Omit them
 * and the window is the business month IN PROGRESS: the 1st to today.
 *
 * The two dates go together or not at all. One of them alone is a request
 * whose other end the server would have to invent — "from the 5th to
 * ...when?" — and a dashboard that quietly answers a different question
 * than the one asked is worse than a 422.
 *
 * The money panel and the chart are SUPERADMIN ONLY, matching how Reports is
 * gated; the gate is applied HERE rather than by middleware because the rest
 * of the payload is for every admin and the response must stay well-formed
 * without it.
 */
final class DashboardController extends Controller
{
    public function __construct(private readonly AdminDashboard $dashboard) {}

    public function show(Request $request): JsonResponse
    {
        return new JsonResponse($this->dashboard->forPeriod(
            $this->window($request),
            $this->isSuperadmin($request),
        ));
    }

    /**
     * GET /api/admin/dashboard/attention — the attention counts ALONE, for
     * the console's nav badges.
     *
     * Same numbers, same predicates, same single round trip as the landing
     * page's first section, and no period: none of the six queues is
     * periodised, so a badge has no window to be asked about.
     *
     * It exists because the shell used to read four of these six by fetching
     * the LISTS behind them — /store-reviews, /change-requests, /holds,
     * /wallet-top-ups — on four independent 60-second timers, purely to pull
     * one scalar off each. That was four HTTP round trips and eight queries a
     * minute for a number this returns in one of each; /holds was the worst,
     * running a grouped reason pass, a merchant join and a paginated lateral
     * join so a badge could read `summary.total`. Worse, four independent
     * timers meant the badge and the dashboard tile it links to were read at
     * different instants — the exact disagreement AttentionQueues is written
     * to make impossible. One key, one poll, one instant.
     *
     * No period means no superadmin gate either: these are counts of work,
     * never money, and the show() docblock's reasoning applies unchanged.
     */
    public function attention(AttentionQueues $queues): JsonResponse
    {
        return new JsonResponse($queues->counts());
    }

    /**
     * The same test EnsureSuperadmin applies to the Reports routes, asked
     * here because it gates two SECTIONS rather than the whole endpoint —
     * the pattern TaxSettingsController already uses for a superadmin-only
     * field on an admin-wide payload.
     */
    private function isSuperadmin(Request $request): bool
    {
        $user = $request->user('admin');

        return $user instanceof AdminUser && $user->role === 'superadmin';
    }

    /**
     * The window, validated exactly as the reports validate theirs — same
     * format, same ordering rule, same 366-day ceiling — because the two
     * screens must be able to be asked the same question.
     */
    private function window(Request $request): ReportPeriod
    {
        $validated = Validator::make($request->query(), [
            // No `sometimes`: it would skip required_with on the very
            // request that rule exists to refuse — the one where the field
            // is missing. An absent field with no implicit rule is skipped
            // by the validator anyway, which is what lets BOTH be omitted.
            'from' => ['required_with:to', 'date_format:Y-m-d'],
            'to' => ['required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
        ])->after(function ($validator): void {
            $data = $validator->getData();

            if ($validator->errors()->isNotEmpty() || ! isset($data['from'], $data['to'])) {
                return;
            }

            $period = ReportPeriod::of((string) $data['from'], (string) $data['to']);

            if ($period->days() > ReportPeriod::MAX_DAYS) {
                $validator->errors()->add('to', sprintf(
                    'A dashboard period covers at most %d days — this one covers %d. Narrow the period.',
                    ReportPeriod::MAX_DAYS,
                    $period->days(),
                ));
            }
        })->validate();

        if (! isset($validated['from'], $validated['to'])) {
            return DashboardPeriod::currentMonth();
        }

        return ReportPeriod::of((string) $validated['from'], (string) $validated['to']);
    }
}
