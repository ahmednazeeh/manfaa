<?php

use App\Domain\Reports\ReportFactory;
use App\Http\Controllers\Admin\ReportsController;
use App\Http\Middleware\EnsureSuperadmin;
use Illuminate\Support\Facades\Route;

// Reporting domain (owner, 2026-08-24): cashback, payouts and earnings, by
// period, previewed as JSON and exported as .xlsx.
//
// Query parameters, identical on both endpoints of every report:
//
//   from             required, Y-m-d, business timezone, inclusive
//   to               required, Y-m-d, business timezone, inclusive
//   merchant_id      optional, narrows every sheet to one merchant
//   include_reversed optional boolean, DEFAULT FALSE — whether transaction
//                    rows in state `reversed` appear. It governs rows only;
//                    the earnings report is ledger-derived and keeps every
//                    reversal journal whatever this says, because there the
//                    reversal is what removes the fee from income.
//
// The preview takes the same parameters as the export so that what an admin
// reads on screen is what the file will contain — the one exception being
// masking, which is the deliberate difference between the two (the preview
// masks names and accounts; the audited workbook does not).
//
// SUPERADMIN ONLY, and not because the numbers are secret from the rest of
// the console — because these three cross every merchant and every customer
// at once. A settlement queue shows one batch; the cashback report shows
// what every shop sold and what every customer earned, with the money trace
// underneath it. That is a different kind of access, and it wears the same
// gate as admin account management and the platform's bank accounts.
//
// The {report} segment is constrained to the three names the factory knows,
// so an unknown report is a 404 from the router rather than a 500 from a
// match expression.
Route::prefix('admin/reports')
    ->middleware(['auth:admin', EnsureSuperadmin::class])
    ->group(function (): void {
        Route::get('{report}', [ReportsController::class, 'show'])
            ->whereIn('report', ReportFactory::KEYS);

        // GET, deliberately, unlike the payout batch export: nothing here
        // mutates a batch or a transfer. The audit row it writes is a
        // record of a READ, and a report a prefetch pulls twice is two
        // honest rows saying the file was built twice.
        Route::get('{report}/export', [ReportsController::class, 'export'])
            ->whereIn('report', ReportFactory::KEYS);
    });
