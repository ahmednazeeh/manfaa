<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Customers\BalanceQuery;
use App\Domain\Customers\CustomerAvatar;
use App\Domain\Customers\Msisdn;
use App\Domain\Mobile\MobileAudience;
use App\Domain\Mobile\MobileTokenService;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerAdminResource;
use App\Http\Resources\CustomerPayoutResource;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The superadmin's customer account controls, beside the merchant controls
 * in MerchantsController/MerchantStaffController and gated the same way:
 * reads are ordinary admin work, every write is EnsureSuperadmin-only (the
 * route file draws that line).
 *
 * This is a SUPPORT surface, not a data-mining one. The list and detail show
 * what a support call needs — who is on the phone, can they sign in, where
 * does their money go — and the payout account number is MASKED exactly as
 * the customer's own payout screens mask it (CustomerPayoutResource): an
 * admin confirming "does it end 4821?" never needs the full digits on
 * screen. The full phone IS shown: it is the login identity this surface
 * exists to verify and correct.
 */
class CustomersController extends Controller
{
    /**
     * Customer statuses the platform honors (customers_status_check).
     * `closed` is terminal bookkeeping — this surface never sets it, and an
     * account already closed is refused below rather than quietly reopened.
     */
    private const array SETTABLE_STATUSES = ['active', 'suspended'];

    /**
     * GET /api/admin/customers — paginated, newest first, searched by name,
     * phone (any way a person types it — Msisdn folds "7712345" into the
     * stored +9607712345 before matching) or customer code.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Customer::query()->orderByDesc('id');

        $q = trim((string) ($validated['q'] ?? ''));

        if ($q !== '') {
            $phone = Msisdn::normalise($q);
            $digits = preg_replace('/\D/', '', $q) ?? '';

            $query->where(function ($where) use ($q, $phone, $digits) {
                $where->where('name', 'ilike', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%');

                if ($phone !== null) {
                    // A complete number, however it was typed, matches
                    // exactly — the same normalise-before-use rule every
                    // auth surface applies.
                    $where->orWhere('phone', $phone);
                } elseif (strlen($digits) >= 3) {
                    // A partial number read off a call screen still finds
                    // the account.
                    $where->orWhere('phone', 'like', '%'.$digits.'%')
                        ->orWhere('customer_code', 'like', $digits.'%');
                }
            });
        }

        return CustomerAdminResource::collection(
            $query->paginate((int) ($validated['per_page'] ?? 25))->appends($request->query()),
        );
    }

    /**
     * GET /api/admin/customers/{customer} — the detail drawer's record:
     * profile, masked payout account, the same balance sums the customer's
     * own balance screen shows (BalanceQuery — stored integers, nothing
     * recomputed), and how many app devices are signed in.
     */
    public function show(Customer $customer, BalanceQuery $balances, MobileTokenService $tokens): JsonResponse
    {
        return response()->json(['data' => $this->detail($customer, $balances, $tokens)]);
    }

    /**
     * PATCH /api/admin/customers/{customer} — superadmin edit over name,
     * email and PHONE. Phone is the sensitive one: it is the login identity
     * and the OTP destination, so it takes the platform's one phone shape
     * (Msisdn — "7712345" folds into +9607712345 before validation, exactly
     * like the auth surfaces) and must not collide with another account.
     *
     * A phone change deliberately does NOT revoke sessions or app tokens.
     * The support scenario this exists for is a customer who LOST the SIM
     * and phoned in from a new number — their app install and web session
     * are still legitimately theirs, and cutting them off would turn a
     * rescue into a lockout. What dies with the old number is what rode on
     * it anyway: OTPs go to the new number from the next request on.
     *
     * phone_verified_at is cleared on a change: it attests an OTP proof of
     * the OLD number, and the next OTP sign-in re-earns it for the new one.
     */
    public function update(Request $request, Customer $customer, BalanceQuery $balances, MobileTokenService $tokens): JsonResponse
    {
        $this->normalisePhone($request);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:120'],
            'email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'phone' => [
                'sometimes', 'string', 'regex:'.Msisdn::PATTERN,
                Rule::unique('customers', 'phone')->ignore($customer->getKey()),
            ],
        ]);

        $previousPhone = $customer->phone;

        $customer->fill($validated);

        if (array_key_exists('phone', $validated) && $validated['phone'] !== $previousPhone) {
            $customer->phone_verified_at = null;

            // Durable trace of an identity change — who moved which account
            // from which number to which. Customers have no notice trail
            // like merchants do, so the application log is the record.
            Log::info('admin.customer.phone_changed', [
                'customer_id' => $customer->getKey(),
                'from' => $previousPhone,
                'to' => $validated['phone'],
                'admin_id' => (int) $request->user('admin')->getKey(),
            ]);
        }

        $customer->save();

        return response()->json(['data' => $this->detail($customer->refresh(), $balances, $tokens)]);
    }

    /**
     * POST /api/admin/customers/{customer}/reset-password — superadmin.
     * A fresh server-generated temporary password, returned EXACTLY ONCE;
     * only the hash survives (the 'hashed' cast). The remember-me token is
     * rotated and every live WEB session dies on its next request — the
     * session's stored password hash no longer matches
     * (AuthenticateMultiGuardSession, the same mechanism the merchant staff
     * reset rides).
     *
     * Mobile tokens are deliberately LEFT ALIVE — the opposite of the staff
     * reset, for a reason worth recording: the customer app is passwordless
     * (OTP sign-in), so the password never guarded it. A customer asking
     * "I forgot my web password" still holds their own phone; killing the
     * app session would be pure collateral. If the account itself is in the
     * wrong hands, the status endpoint below is the lever — that one does
     * destroy everything.
     */
    public function resetPassword(Request $request, Customer $customer, BalanceQuery $balances, MobileTokenService $tokens): JsonResponse
    {
        $tempPassword = Str::password(20);

        $customer->password = $tempPassword;
        $customer->setRememberToken(Str::random(60));
        $customer->save();

        Log::info('admin.customer.password_reset', [
            'customer_id' => $customer->getKey(),
            'admin_id' => (int) $request->user('admin')->getKey(),
        ]);

        return response()->json([
            'data' => $this->detail($customer, $balances, $tokens),
            // Shown once; the hash is all that survives.
            'temp_password' => $tempPassword,
        ]);
    }

    /**
     * POST /api/admin/customers/{customer}/status — superadmin
     * enable/disable. `suspended` is the platform's disabled state (the
     * literal every auth path already refuses: mayUseMobileApp, the OTP
     * verify's account_unavailable, the web login's post-attempt check) and
     * it is reversible; `closed` is ledger bookkeeping this endpoint never
     * sets, and a closed account is refused rather than quietly reopened.
     *
     * Suspending destroys every mobile token outright — device push
     * registrations cascade with them (device_tokens FK), so a suspended
     * account's phone goes quiet too — and the Authenticated listener
     * (CustomersServiceProvider) logs any live web session out on its next
     * request, the same way merchant staff deactivation works. Reactivating
     * later must not resurrect a token on a phone nobody holds anymore,
     * which is why the tokens are deleted rather than merely refused.
     */
    public function status(Request $request, Customer $customer, MobileTokenService $tokens): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(self::SETTABLE_STATUSES)],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        if ($customer->status === 'closed') {
            abort(409, sprintf('Customer #%d is closed — a closed account keeps its rows for the ledger and cannot be reopened here.', $customer->getKey()));
        }

        $to = $validated['status'];

        if ($customer->status !== $to) {
            $customer->update(['status' => $to]);

            if ($to === 'suspended') {
                $tokens->revokeEverything($customer);
            }

            Log::info('admin.customer.status_changed', [
                'customer_id' => $customer->getKey(),
                'to' => $to,
                'reason' => $validated['reason'] ?? null,
                'admin_id' => (int) $request->user('admin')->getKey(),
            ]);
        }

        return response()->json([
            'data' => [
                'id' => $customer->getKey(),
                'status' => $customer->refresh()->status,
            ],
        ]);
    }

    /**
     * The full record the detail drawer renders — shared by show() and every
     * write, so a save answers with exactly what a re-read would.
     *
     * @return array<string, mixed>
     */
    private function detail(Customer $customer, BalanceQuery $balances, MobileTokenService $tokens): array
    {
        $sums = $balances->balances(
            $customer,
            CarbonImmutable::now('UTC'),
            (string) config('app.business_timezone', 'Indian/Maldives'),
        );

        // Same three-field test the customer's own balance screen applies.
        $hasPayoutAccount = filled($customer->payout_bank)
            && filled($customer->payout_account)
            && filled($customer->payout_account_name);

        return [
            'id' => $customer->getKey(),
            'customer_code' => $customer->customer_code,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'phone_verified_at' => $customer->phone_verified_at?->toIso8601String(),
            'email' => $customer->email,
            'status' => $customer->status,
            'kyc_status' => $customer->kyc_status,
            'avatar_url' => CustomerAvatar::url($customer),
            'has_payout_account' => $hasPayoutAccount,
            // Masked exactly as the customer's own payout history masks it —
            // support confirms the tail digits, never reads the full number.
            'payout_account' => $hasPayoutAccount ? [
                'bank' => $customer->payout_bank,
                'account_masked' => CustomerPayoutResource::maskAccount($customer->payout_account),
                'account_name' => $customer->payout_account_name,
            ] : null,
            'balance' => [
                'currency' => 'MVR',
                'confirmed_laari' => $sums['confirmed_laari'],
                'pending_laari' => $sums['pending_laari'],
                'paid_this_month_laari' => $sums['paid_this_month_laari'],
            ],
            // Live app sign-ins — what the customer's own device screen
            // counts, so "you appear signed in on 2 phones" is answerable.
            'devices_count' => $tokens->devices($customer, MobileAudience::Customer)->count(),
            'created_at' => $customer->created_at?->toIso8601String(),
        ];
    }

    /**
     * Folds whatever the admin typed into the stored E.164 shape BEFORE
     * validation — the same rule, in the same place, as every customer auth
     * surface (OtpAuthController, CustomerOtpController): phone keys the
     * account, so the two representations must meet before the value is
     * compared or stored. Unparseable input is left as typed for the regex
     * rule to refuse.
     */
    private function normalisePhone(Request $request): void
    {
        $raw = $request->input('phone');

        if (is_string($raw)) {
            $request->merge(['phone' => Msisdn::normalise($raw) ?? $raw]);
        }
    }
}
