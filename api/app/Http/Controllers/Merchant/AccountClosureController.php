<?php

declare(strict_types=1);

namespace App\Http\Controllers\Merchant;

use App\Domain\Customers\InvalidOtpException;
use App\Domain\Customers\Msisdn;
use App\Domain\Customers\TooManyOtpAttemptsException;
use App\Domain\Discovery\DiscoveryService;
use App\Domain\Onboarding\MerchantOtpService;
use App\Domain\Settlement\OutstandingSummary;
use App\Http\Controllers\Controller;
use App\Http\Support\MerchantOtpRequestLimiter;
use App\Models\DeviceToken;
use App\Models\Merchant;
use App\Models\MerchantUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Self-service store closure from the public /account-deletion page
 * (store-readiness 2026-08-17), the merchant twin of the customer flow:
 * prove possession of the store's contact phone with an OTP, see each
 * store on that number with its outstanding balance, close the ones that
 * are settled. A store owing money cannot close — settling stays open
 * (the panel never locks it), closing waits.
 *
 * Business, transaction and settlement records survive closure untouched:
 * they are the financial ledger. What closure does is take the store off
 * the storefront, stop crediting, and shut every staff door.
 */
class AccountClosureController extends Controller
{
    private const TOKEN_TTL_MINUTES = 15;

    public function __construct(
        private readonly MerchantOtpService $otp,
        private readonly OutstandingSummary $outstanding,
    ) {}

    public function requestOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string'],
        ]);

        $phone = Msisdn::normalise($validated['phone']);

        if ($phone === null) {
            throw ValidationException::withMessages(['phone' => 'phone_invalid']);
        }

        if ($refusal = MerchantOtpRequestLimiter::hitOrRefuse($phone, (string) $request->ip())) {
            return $refusal;
        }

        $this->otp->request($phone);

        return response()->json(['message' => 'If the number is valid, a verification code has been sent.']);
    }

    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string'],
            'code' => ['required', 'string', 'digits:6'],
        ]);

        $phone = Msisdn::normalise($validated['phone']) ?? $validated['phone'];

        try {
            $this->otp->verify($phone, $validated['code']);
        } catch (TooManyOtpAttemptsException) {
            throw ValidationException::withMessages(['code' => 'otp_attempts_exceeded']);
        } catch (InvalidOtpException) {
            throw ValidationException::withMessages(['code' => 'otp_invalid']);
        }

        $stores = Merchant::query()
            ->where('contact_phone', $phone)
            ->where('status', '!=', 'closed')
            ->get();

        if ($stores->isEmpty()) {
            throw ValidationException::withMessages(['phone' => 'no_store']);
        }

        $token = Str::random(48);
        Cache::put('store-closure:'.$token, $phone, now()->addMinutes(self::TOKEN_TTL_MINUTES));

        return response()->json([
            'data' => [
                'closure_token' => $token,
                'expires_in_minutes' => self::TOKEN_TTL_MINUTES,
                'stores' => $stores->map(function (Merchant $store): array {
                    $outstanding = $this->outstanding->forMerchant($store);
                    $payable = (int) ($outstanding['total']['payable_laari'] ?? 0);

                    return [
                        'id' => $store->id,
                        'name' => $store->name,
                        'status' => $store->status,
                        'outstanding_laari' => $payable,
                        'can_close' => $payable === 0,
                    ];
                })->values(),
            ],
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'closure_token' => ['required', 'string'],
            'merchant_id' => ['required', 'integer'],
        ]);

        $phone = Cache::get('store-closure:'.$validated['closure_token']);

        if ($phone === null) {
            throw ValidationException::withMessages(['closure_token' => 'closure_token_invalid']);
        }

        $store = Merchant::query()
            ->where('id', $validated['merchant_id'])
            ->where('contact_phone', $phone)
            ->where('status', '!=', 'closed')
            ->first();

        if ($store === null) {
            throw ValidationException::withMessages(['merchant_id' => 'closure_token_invalid']);
        }

        $outstanding = $this->outstanding->forMerchant($store);

        if ((int) ($outstanding['total']['payable_laari'] ?? 0) > 0) {
            throw ValidationException::withMessages(['merchant_id' => 'outstanding_balance']);
        }

        DB::transaction(function () use ($store): void {
            $store->update(['status' => 'closed']);

            MerchantUser::query()
                ->where('merchant_id', $store->id)
                ->update(['is_active' => false]);

            foreach (MerchantUser::query()->where('merchant_id', $store->id)->get() as $staff) {
                $staff->tokens()->delete();

                DeviceToken::query()
                    ->where('tokenable_type', $staff->getMorphClass())
                    ->where('tokenable_id', $staff->id)
                    ->delete();
            }
        });

        DiscoveryService::forgetMerchant($store);

        return response()->json(['message' => 'Store closed.']);
    }
}
