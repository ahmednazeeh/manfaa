<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Domain\Customers\AccountDeletionService;
use App\Domain\Customers\BalanceQuery;
use App\Domain\Customers\InvalidOtpException;
use App\Domain\Customers\Msisdn;
use App\Domain\Customers\OtpService;
use App\Domain\Customers\TooManyOtpAttemptsException;
use App\Http\Controllers\Controller;
use App\Http\Support\OtpRequestLimiter;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Self-service account deletion (store-readiness 2026-08-17): the public
 * /account-deletion page proves phone possession with an OTP, shows the
 * member exactly what deleting costs (an unpaid balance lapses), and only
 * then deletes. No support ticket in the loop — Apple and Play both refuse
 * contact-support-only deletion paths.
 *
 * request-otp answers identically for known and unknown numbers (the same
 * anti-enumeration stance as signup); which of the two it was is revealed
 * only AFTER the caller has proven they hold the phone.
 */
class AccountDeletionController extends Controller
{
    private const TOKEN_TTL_MINUTES = 15;

    public function __construct(
        private readonly OtpService $otp,
        private readonly BalanceQuery $balances,
        private readonly AccountDeletionService $deletion,
    ) {}

    public function requestOtp(Request $request): JsonResponse
    {
        $this->normalisePhone($request);

        $validated = $request->validate([
            'phone' => ['required', 'string', OtpAuthController::PHONE_RULE],
        ]);

        // The SAME dual limiter as sign-up OTPs: one shared budget per
        // phone/IP, so this endpoint cannot be used to sidestep it.
        if ($refusal = OtpRequestLimiter::hitOrRefuse($validated['phone'], (string) $request->ip())) {
            return $refusal;
        }

        $this->otp->request($validated['phone']);

        return response()->json(['message' => 'If the number is valid, a verification code has been sent.']);
    }

    public function verify(Request $request): JsonResponse
    {
        $this->normalisePhone($request);

        $validated = $request->validate([
            'phone' => ['required', 'string', OtpAuthController::PHONE_RULE],
            'code' => ['required', 'string', 'digits:6'],
        ]);

        try {
            $this->otp->verify($validated['phone'], $validated['code']);
        } catch (TooManyOtpAttemptsException) {
            throw ValidationException::withMessages(['code' => 'otp_attempts_exceeded']);
        } catch (InvalidOtpException) {
            throw ValidationException::withMessages(['code' => 'otp_invalid']);
        }

        $customer = Customer::query()
            ->where('phone', $validated['phone'])
            ->where('status', '!=', 'closed')
            ->first();

        // Phone possession is proven, so naming the truth is safe now.
        if ($customer === null) {
            throw ValidationException::withMessages(['phone' => 'no_account']);
        }

        $token = Str::random(48);
        Cache::put('account-deletion:'.$token, $customer->id, now()->addMinutes(self::TOKEN_TTL_MINUTES));

        $balances = $this->balances->balances(
            $customer,
            CarbonImmutable::now('UTC'),
            (string) config('app.business_timezone', 'Indian/Maldives'),
        );

        return response()->json([
            'data' => [
                'deletion_token' => $token,
                'name' => $customer->name,
                'customer_code' => $customer->customer_code,
                'confirmed_laari' => $balances['confirmed_laari'],
                'pending_laari' => $balances['pending_laari'],
                'expires_in_minutes' => self::TOKEN_TTL_MINUTES,
            ],
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'deletion_token' => ['required', 'string'],
        ]);

        $customerId = Cache::pull('account-deletion:'.$validated['deletion_token']);

        if ($customerId === null) {
            throw ValidationException::withMessages(['deletion_token' => 'deletion_token_invalid']);
        }

        $customer = Customer::query()->find($customerId);

        if ($customer === null || $customer->status === 'closed') {
            throw ValidationException::withMessages(['deletion_token' => 'deletion_token_invalid']);
        }

        $this->deletion->delete($customer);

        return response()->json(['message' => 'Account deleted.']);
    }

    private function normalisePhone(Request $request): void
    {
        $raw = $request->input('phone');

        if (is_string($raw)) {
            $request->merge(['phone' => Msisdn::normalise($raw) ?? $raw]);
        }
    }
}
