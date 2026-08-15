<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use Illuminate\Support\Facades\Log;

/**
 * Development stand-in for a real SMS provider (PLAN §14): records that a
 * message was sent instead of sending one. MsgOwlSmsSender is the production
 * driver; this one is only bound when no provider key is configured.
 *
 * NOTHING SENSITIVE IS LOGGED. Every message this class carries is a
 * verification code, and application logs are the least protected store we
 * have — they are read over someone's shoulder, shipped to third-party log
 * services, and pasted into chats. So the code never reaches the log (a
 * partial code would not help either: with a 5-attempt cap, leaking even
 * three digits turns a 1-in-a-million guess into a coin flip), and the phone
 * is masked to the same shape MsgOwlSmsSender uses on its failure path.
 *
 * A developer who genuinely needs the plaintext locally should bind their own
 * SmsSender in a local-only service provider — never widen this one.
 */
final class LogSmsSender implements SmsSender
{
    public function send(string $phone, string $message): void
    {
        Log::info('SMS (log sender — no real provider configured)', [
            'phone' => self::maskPhone($phone),
            'message' => self::maskDigits($message),
        ]);
    }

    /** +9607712345 → +960****345: enough to tell two numbers apart, not enough to dial one. */
    private static function maskPhone(string $phone): string
    {
        if (strlen($phone) <= 7) {
            return str_repeat('*', strlen($phone));
        }

        return substr($phone, 0, 4).str_repeat('*', strlen($phone) - 7).substr($phone, -3);
    }

    /**
     * Replaces every run of 3+ digits in the body — the code, and anything
     * else numeric that might join the template later. The surrounding copy
     * stays readable so a developer can still see WHICH message went out.
     */
    private static function maskDigits(string $message): string
    {
        return (string) preg_replace_callback(
            '/\d{3,}/',
            static fn (array $m): string => str_repeat('*', strlen($m[0])),
            $message,
        );
    }
}
