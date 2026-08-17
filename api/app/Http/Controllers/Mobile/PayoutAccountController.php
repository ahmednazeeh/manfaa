<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Domain\Customers\InvalidOtpException;
use App\Domain\Customers\OtpService;
use App\Domain\Customers\TooManyOtpAttemptsException;
use App\Domain\Platform\Bank;
use App\Http\Controllers\Controller;
use App\Http\Support\OtpRequestLimiter;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The customer app's payout account (R5) — with a FRESH-OTP GATE the web
 * version does not have.
 *
 * The change is money-critical: it is where the platform is told which bank
 * account to send someone's cashback to. A 365-day mobile bearer token must
 * not be enough to redirect it — a stolen phone or an exfiltrated token
 * would otherwise let a thief point the payouts at their own account. So a
 * change demands a fresh code delivered to the number on file (the
 * SIM-swap / stolen-token mitigation recorded in the round plan): request a
 * code, then submit the new details WITH that code.
 *
 * Reading and clearing carry no gate — seeing your own account, or removing
 * it, moves no money to a new destination.
 *
 * Snapshot semantics are the web controller's: payout_items copy the bank
 * details at BATCH BUILD time, so a change while a batch is processing is
 * safe and applies from the next batch.
 */
final class PayoutAccountController extends Controller
{
    public function __construct(private readonly OtpService $otp) {}

    public function show(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        return $this->payload($customer);
    }

    /**
     * Send the confirmation code to the customer's OWN number on file. Same
     * shared SMS budget as sign-in (OtpRequestLimiter) — a number cannot be
     * bombed from this surface any more than from that one.
     */
    public function requestOtp(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        $phone = trim((string) $customer->phone);

        if ($phone === '') {
            // Every account has a verified phone (it is how they were
            // created), so this is defensive, not a real state.
            return new JsonResponse([
                'message' => 'No phone number is on file for this account.',
                'code' => 'no_phone_on_file',
            ], 422);
        }

        if ($refusal = OtpRequestLimiter::hitOrRefuse($phone, (string) $request->ip())) {
            return $refusal;
        }

        $this->otp->request($phone);

        return new JsonResponse([
            'data' => [
                'sent' => true,
                'expires_in_minutes' => OtpService::CODE_TTL_MINUTES,
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bank_name' => ['required', Rule::enum(Bank::class)],
            'account_no' => ['required', 'string', 'max:32', 'regex:/^\d{6,32}$/'],
            'account_name' => ['required', 'string', 'max:120'],
            'otp_code' => ['required', 'string', 'digits:6'],
        ]);

        /** @var Customer $customer */
        $customer = $request->user('customer');

        // Prove possession of the number on file BEFORE the write. A wrong
        // or expired code refuses with its own machine code; five wrong
        // guesses kill the code exactly as sign-in does.
        try {
            $this->otp->confirmPossession(
                trim((string) $customer->phone),
                $validated['otp_code'],
            );
        } catch (TooManyOtpAttemptsException) {
            return new JsonResponse([
                'message' => 'Too many wrong codes. Request a fresh one and try again.',
                'code' => 'otp_attempts_exceeded',
            ], 422);
        } catch (InvalidOtpException) {
            return new JsonResponse([
                'message' => 'That code is not right or has expired. Request a fresh one.',
                'code' => 'otp_invalid',
            ], 422);
        }

        $customer->forceFill([
            'payout_bank' => $validated['bank_name'],
            'payout_account' => $validated['account_no'],
            'payout_account_name' => $validated['account_name'],
        ])->save();

        return $this->payload($customer);
    }

    private function payload(Customer $customer): JsonResponse
    {
        $has = filled($customer->payout_bank)
            && filled($customer->payout_account)
            && filled($customer->payout_account_name);

        return new JsonResponse([
            'data' => [
                'bank_name' => $customer->payout_bank,
                // The FULL number, deliberately: this is the customer's own
                // edit screen, and they must see what is on file to confirm
                // it. It is masked only where a payout is SHOWN (a
                // screenshot-prone list), not where it is set.
                'account_no' => $customer->payout_account,
                'account_name' => $customer->payout_account_name,
                'has_payout_account' => $has,
                // A key the app translates: a change during a processing
                // batch applies from the next one.
                'change_effective' => 'next_batch',
            ],
        ]);
    }
}
