<?php

namespace App\Http\Controllers\Customer;

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
 * Payout account registration (§10 apps/web) — with the SAME fresh-OTP gate
 * the app enforces (closed 2026-08-17; the website shipped without it, so a
 * hijacked browser session could redirect someone's cashback with no proof
 * of the phone).
 *
 * The change is money-critical: it tells the platform which bank account to
 * send someone's cashback to. A session cookie must not be enough — the
 * change demands a fresh code delivered to the number on file: request a
 * code, then submit the new details WITH that code. Mirrors
 * Mobile\PayoutAccountController exactly, on the session guard.
 *
 * Snapshot semantics (existing since Phase 1): payout_items copy bank /
 * account / account_name from the customer row at BATCH BUILD time. Changing
 * the account here while a payout batch is processing is therefore allowed
 * and safe — the in-flight batch keeps paying the snapshotted account, and
 * the change takes effect from the next batch build.
 */
class PayoutAccountController extends Controller
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

    public function store(Request $request): JsonResponse
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

        return response()->json([
            'data' => [
                'bank_name' => $customer->payout_bank,
                'account_no' => $customer->payout_account,
                'account_name' => $customer->payout_account_name,
                'has_payout_account' => $has,
                // Key, not prose (frontend translates): a change during a
                // processing batch applies from the next batch.
                'change_effective' => 'next_batch',
            ],
        ]);
    }
}
