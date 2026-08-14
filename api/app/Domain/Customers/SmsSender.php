<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use Illuminate\Container\Attributes\Bind;

/**
 * Outbound SMS delivery for customer phone verification.
 *
 * SWAP POINT (PLAN §14 open item — "SMS OTP provider"): the real provider is
 * undecided (local bulk SMS via Ooredoo / Dhiraagu vs. an international
 * gateway). Until it lands, LogSmsSender is bound here via the container
 * #[Bind] attribute; to go live, implement this interface against the chosen
 * provider's API and repoint the attribute (or override the binding in a
 * service provider). Nothing else in the OTP flow changes — OtpService only
 * ever sees this interface.
 */
#[Bind(LogSmsSender::class)]
interface SmsSender
{
    public function send(string $phone, string $message): void;
}
