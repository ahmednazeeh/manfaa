<?php

namespace App\Http\Controllers\Customer;

use App\Domain\Claims\ClaimPolicy;
use App\Domain\Claims\ClaimState;
use App\Http\Controllers\Controller;
use App\Http\Resources\ClaimResource;
use App\Models\Claim;
use App\Models\Customer;
use App\Models\Merchant;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Missing-transaction claims (§10 apps/web): the customer bought something,
 * the till never POSTed it. Submission opens a claim for the admin queue;
 * nothing accrues until an admin approves it there.
 */
class ClaimsController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        /** @var Customer $customer */
        $customer = $request->user('customer');

        return ClaimResource::collection(
            $customer->claims()
                ->with('merchant:id,name,slug')
                ->orderByDesc('id')
                ->paginate((int) ($validated['per_page'] ?? 25))
                ->appends($request->query()),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $timezone = (string) config('app.business_timezone', 'Indian/Maldives');
        $today = CarbonImmutable::now($timezone);

        $validated = $request->validate([
            // Slug, not id: the only merchant source a customer sees is the
            // public /discover payload, whose privacy contract exposes slugs
            // and never internal ids. Resolved server-side below.
            'merchant_slug' => ['required', 'string', 'exists:merchants,slug'],
            // Within the 90-day window (day 90 passes, day 91 fails) and
            // never in the future — evaluated as business-timezone dates.
            'purchased_at' => [
                'required',
                'date_format:Y-m-d',
                'before_or_equal:'.$today->toDateString(),
                'after_or_equal:'.$today->subDays(ClaimPolicy::WINDOW_DAYS)->toDateString(),
            ],
            'amount_laari' => ['required', 'integer', 'min:1'],
            'receipt_no' => ['required', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        /** @var Customer $customer */
        $customer = $request->user('customer');

        $merchantId = Merchant::query()
            ->where('slug', $validated['merchant_slug'])
            ->value('id');
        $validated['merchant_id'] = $merchantId;

        // Dedupe guard: one live claim per (customer, merchant, receipt,
        // date). A rejected claim may be corrected and refiled; an open,
        // in-review or approved one blocks identical resubmission — without
        // this, one customer floods the admin queue with copies of the same
        // claim. Backed by a partial unique index for the concurrent case.
        $duplicate = Claim::query()
            ->where('customer_id', $customer->id)
            ->where('merchant_id', $validated['merchant_id'])
            ->where('receipt_no', $validated['receipt_no'])
            ->where('claimed_date', $validated['purchased_at'])
            ->where('state', '!=', ClaimState::Rejected->value)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'receipt_no' => 'You have already submitted a claim for this receipt.',
            ]);
        }

        try {
            $claim = Claim::query()->create([
                'merchant_id' => $validated['merchant_id'],
                'customer_id' => $customer->id,
                'claimed_date' => $validated['purchased_at'],
                'claimed_amount_laari' => $validated['amount_laari'],
                'currency' => 'MVR',
                'receipt_no' => $validated['receipt_no'],
                'note' => $validated['note'] ?? null,
                'state' => ClaimState::Open->value,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two identical submissions raced past the check — same answer.
            throw ValidationException::withMessages([
                'receipt_no' => 'You have already submitted a claim for this receipt.',
            ]);
        }

        return (new ClaimResource($claim->load('merchant:id,name,slug')))
            ->response($request)
            ->setStatusCode(201);
    }
}
