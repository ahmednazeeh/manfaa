<?php

declare(strict_types=1);

namespace App\Http\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The dual throttle on requesting a merchant SIGNUP OTP: 3/hour per phone so
 * a number cannot be SMS-bombed, 10/hour per IP so one caller cannot spray
 * codes across many numbers. The merchant-scoped sibling of
 * OtpRequestLimiter, keyed separately from the customer flow on purpose —
 * the two flows have separate storage and must have separate budgets.
 *
 * ONE class because the WEB signup and the MOBILE signup must share one
 * budget: the keys are deliberately identical across surfaces (they are the
 * keys the web SignupController used inline before this class existed), or
 * an attacker would simply alternate endpoints and double every limit.
 * Route-level throttles on both are coarse backstops; this is the real
 * control.
 */
final class MerchantOtpRequestLimiter
{
    public const int PHONE_LIMIT_PER_HOUR = 3;

    public const int IP_LIMIT_PER_HOUR = 10;

    private const int WINDOW_SECONDS = 3600;

    /**
     * Counts the attempt, or refuses with a 429 carrying Retry-After.
     * On the mobile tree NormalisesMobileErrors reshapes the body into the
     * envelope (`rate_limited`) and the header survives.
     */
    public static function hitOrRefuse(string $phone, string $ip): ?JsonResponse
    {
        $phoneKey = 'merchant-otp-request:phone:'.$phone;
        $ipKey = 'merchant-otp-request:ip:'.$ip;

        if (RateLimiter::tooManyAttempts($phoneKey, self::PHONE_LIMIT_PER_HOUR)
            || RateLimiter::tooManyAttempts($ipKey, self::IP_LIMIT_PER_HOUR)) {
            $retryAfter = max(
                RateLimiter::availableIn($phoneKey),
                RateLimiter::availableIn($ipKey),
            );

            // retry_after_seconds in the BODY, not only the header — same
            // reasoning as OtpRequestLimiter: the mobile envelope lifts it
            // into error.meta, and the Dart client reads the body, not
            // headers. Additive key; the web response is unchanged in every
            // way it reads.
            return new JsonResponse(
                [
                    'message' => 'Too many verification requests. Try again later.',
                    'retry_after_seconds' => $retryAfter,
                ],
                429,
                ['Retry-After' => (string) $retryAfter],
            );
        }

        RateLimiter::hit($phoneKey, self::WINDOW_SECONDS);
        RateLimiter::hit($ipKey, self::WINDOW_SECONDS);

        return null;
    }
}
