<?php

namespace App\Http\Controllers\Merchant;

use App\Domain\Money\Laari;
use App\Domain\Platform\PlatformConfig;
use App\Domain\Settlement\DuplicateBankRefException;
use App\Domain\Settlement\InvalidSlipException;
use App\Domain\Settlement\SlipStorage;
use App\Domain\Settlement\TooManyPendingTopUpsException;
use App\Domain\Settlement\WalletTopUpBelowMinimumException;
use App\Domain\Settlement\WalletTopUps;
use App\Http\Controllers\Controller;
use App\Http\Resources\WalletTopUpResource;
use App\Models\MerchantUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The merchant's wallet top-up claim (owner, 2026-08-24): multipart, the
 * same shape as the receipt-first settlement submission — the amount
 * transferred, WHICH platform account it went to, the slip, and the bank
 * reference if they have it. Creates a pending claim that the bank-history
 * verifier (or an admin) turns into a wallet credit.
 */
class WalletTopUpController extends Controller
{
    public function store(Request $request, WalletTopUps $topUps, PlatformConfig $config): JsonResponse
    {
        $minimum = $config->walletTopUpMinLaari();

        $validated = $request->validate([
            // The platform minimum is a FIELD error here so the form can say
            // so next to the box; the domain enforces it again regardless.
            'amount' => ['required', 'integer', 'min:'.$minimum],
            'platform_bank_account_id' => [
                'required', 'integer',
                Rule::exists('platform_bank_accounts', 'id')->where('active', true),
            ],
            'bank_ref' => ['sometimes', 'nullable', 'string', 'max:128'],
            // First-pass gate only. The authority on what this file IS lives
            // in SlipStorage, which reads the magic bytes.
            'slip' => ['required', 'file', 'max:'.intdiv(SlipStorage::MAX_BYTES, 1024)],
        ]);

        /** @var MerchantUser $user */
        $user = $request->user('merchant');

        try {
            $topUp = $topUps->claim(
                $user->merchant,
                $user,
                Laari::of((int) $validated['amount']),
                (int) $validated['platform_bank_account_id'],
                $validated['bank_ref'] ?? null,
                $request->file('slip'),
            );
        } catch (InvalidSlipException $e) {
            return new JsonResponse(['message' => $e->getMessage(), 'code' => $e->errorCode], 422);
        } catch (WalletTopUpBelowMinimumException $e) {
            return new JsonResponse(['message' => $e->getMessage(), 'code' => 'top_up_below_minimum'], 422);
        } catch (DuplicateBankRefException $e) {
            return new JsonResponse(['message' => $e->getMessage(), 'code' => 'duplicate_bank_ref'], 409);
        } catch (TooManyPendingTopUpsException $e) {
            return new JsonResponse(['message' => $e->getMessage(), 'code' => 'too_many_pending_top_ups'], 409);
        }

        return (new WalletTopUpResource($topUp->load('platformBankAccount')))
            ->response($request)
            ->setStatusCode(201);
    }
}
