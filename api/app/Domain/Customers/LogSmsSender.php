<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use Illuminate\Support\Facades\Log;

/**
 * Development stand-in for the undecided SMS provider (PLAN §14): writes the
 * message to the application log instead of a phone. Replace by binding a
 * real SmsSender implementation once the provider is chosen.
 */
final class LogSmsSender implements SmsSender
{
    public function send(string $phone, string $message): void
    {
        Log::info('SMS (log sender — no real provider configured)', [
            'phone' => $phone,
            'message' => $message,
        ]);
    }
}
