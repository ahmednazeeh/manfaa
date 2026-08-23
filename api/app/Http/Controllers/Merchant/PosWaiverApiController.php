<?php

declare(strict_types=1);

namespace App\Http\Controllers\Merchant;

use App\Domain\PosWaiver\PosWaiverEvaluator;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /v1/merchants/me/pos-waiver` — the same payload the panel card
 * reads, over a merchant credential (ability rates:read), so a POS can
 * show its merchant how close this month is to a free invoice.
 */
final class PosWaiverApiController extends Controller
{
    public function show(Request $request, PosWaiverEvaluator $evaluator): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->user();

        return new JsonResponse(['data' => PosWaiverController::payload($merchant, $evaluator)]);
    }
}
