<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Domain\Connect\ConnectException;
use App\Domain\Connect\ConnectService;
use App\Domain\PosWaiver\PosWaiverEvaluator;
use App\Models\ApiCredential;
use App\Models\Merchant;
use App\Models\PosVendor;
use App\Models\PosWaiverEvaluation;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * A platform reads which of its merchants earned the month's fee waiver
 * (owner, 2026-08-23): IsleBooks' invoice job asks this once a month and
 * applies a 100% discount line to qualifying tenants. Verdicts and the
 * figures behind them — never raw transactions.
 *
 * Authenticated as the platform itself (HTTP Basic, client_id:client_secret),
 * like GET /v1/connect/webhooks. Confidential platforms only.
 */
final class ConnectWaiversController extends V1Controller
{
    public function index(Request $request, ConnectService $connect, PosWaiverEvaluator $evaluator): JsonResponse
    {
        $vendor = $this->platform($request, $connect);

        if ($vendor instanceof JsonResponse) {
            return $vendor;
        }

        $timezone = (string) config('app.business_timezone', 'Indian/Maldives');
        $monthInput = (string) $request->query('month', '');

        try {
            $month = $monthInput === ''
                ? CarbonImmutable::now($timezone)->subMonthNoOverflow()->startOfMonth()
                : CarbonImmutable::createFromFormat('!Y-m', $monthInput, $timezone);
        } catch (\Throwable) {
            $month = null;
        }

        if ($month === null || CarbonImmutable::now($timezone)->startOfMonth()->lessThanOrEqualTo($month)) {
            return $this->error(422, 'validation_failed', 'month must be a CLOSED month, formatted YYYY-MM.');
        }

        // The platform's merchants: everyone holding one of its live
        // credentials. Missing rows are evaluated lazily — the scheduler
        // normally beats any caller here, but a first-of-month poll must
        // not read an empty programme.
        $merchantIds = ApiCredential::query()
            ->where('pos_vendor_id', $vendor->getKey())
            ->whereNull('revoked_at')
            ->distinct()
            ->pluck('merchant_id');

        $rows = [];

        foreach (Merchant::query()->whereKey($merchantIds)->orderBy('id')->get() as $merchant) {
            $evaluation = PosWaiverEvaluation::query()
                ->where('merchant_id', $merchant->getKey())
                ->whereDate('month', $month->toDateString())
                ->first() ?? $evaluator->evaluate($merchant, $month);

            $rows[] = [
                'merchant_id' => $merchant->getKey(),
                'qualified' => (bool) $evaluation->qualified,
                'volume_laari' => (int) $evaluation->volume_laari,
                'cashback_laari' => (int) $evaluation->cashback_laari,
                'min_rate_bp' => (int) $evaluation->min_rate_bp,
                'overdue_laari' => (int) $evaluation->overdue_laari,
                'merchant_status' => $evaluation->merchant_status,
                'evaluated_at' => $evaluation->evaluated_at?->toIso8601String(),
            ];
        }

        return new JsonResponse([
            'month' => $month->format('Y-m'),
            'criteria' => [
                'min_rate_bp' => PosWaiverEvaluator::MIN_RATE_BP,
                'volume_threshold_laari' => PosWaiverEvaluator::VOLUME_THRESHOLD_LAARI,
                'cashback_threshold_laari' => PosWaiverEvaluator::CASHBACK_THRESHOLD_LAARI,
            ],
            'data' => $rows,
        ]);
    }

    /** Same Basic-auth resolution as ConnectWebhooksController. */
    private function platform(Request $request, ConnectService $connect): PosVendor|JsonResponse
    {
        $clientId = (string) $request->getUser();
        $clientSecret = (string) $request->getPassword();

        if ($clientId === '' || $clientSecret === '') {
            return new JsonResponse([
                'error' => 'invalid_client',
                'error_description' => 'Authenticate with HTTP Basic: your client_id as the username and client_secret as the password.',
            ], 401, ['WWW-Authenticate' => 'Basic realm="Manfaa platform"']);
        }

        try {
            $vendor = $connect->client($clientId);
        } catch (ConnectException $e) {
            return new JsonResponse(['error' => $e->errorCode, 'error_description' => $e->getMessage()], 401);
        }

        if ($vendor->isPublicClient() || ! Hash::check($clientSecret, (string) $vendor->client_secret_hash)) {
            return new JsonResponse(['error' => 'invalid_client', 'error_description' => 'That application could not be authenticated.'], 401);
        }

        return $vendor;
    }
}
