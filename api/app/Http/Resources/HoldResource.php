<?php

namespace App\Http\Resources;

use App\Domain\Cashback\HoldReviewService;
use App\Domain\Cashback\TransactionState;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the admin hold-review queue: everything a reviewer needs to
 * decide, and nothing more.
 *
 * The customer is deliberately reduced to their code plus a MASKED name — the
 * same idiom the merchant and /v1 lookups use. A reviewer needs to recognise
 * the person a hold concerns, not to read the platform's customer table.
 *
 * `reason_code` is the HOLD's own reason from the event history, falling back
 * to the row. The row's copy is rewritten by later hops (a released row says
 * `admin_release`), so the append-only event is the only durable record of
 * why this review was opened.
 *
 * @mixin Transaction
 */
class HoldResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $now = CarbonImmutable::now('UTC');
        $heldAt = $this->heldAt();
        $accrued = HoldReviewService::accruedLaari($this->resource);
        $preHoldState = $this->pre_hold_state === null ? null : (string) $this->pre_hold_state;
        $target = HoldReviewService::releaseTarget($this->resource, $preHoldState, $now);

        return [
            'id' => $this->id,
            'state' => $this->state->value,
            'merchant' => [
                'id' => $this->merchant->id,
                'name' => $this->merchant->name,
                'slug' => $this->merchant->slug,
            ],
            'customer' => $this->customer === null ? null : [
                'customer_code' => $this->customer->customer_code,
                'masked_name' => self::maskName((string) $this->customer->name),
            ],
            'invoice_no' => $this->invoice_no,
            'origin' => $this->origin,
            'currency' => $this->currency,
            'eligible_laari' => (int) $this->eligible_laari,
            'cashback_laari' => (int) $this->cashback_laari,
            'fee_laari' => (int) $this->fee_laari,
            'fee_gst_laari' => (int) $this->fee_gst_laari,
            // What a reject would mirror out of the ledger. Zero means the
            // accrual never posted (a zeroed credit), so a reject reverses the
            // state and books nothing — the queue says so before it is clicked.
            'accrued_laari' => $accrued,
            'has_accrual' => $accrued > 0,
            'reason_code' => $this->holdReasonCode(),
            // PLAN §1: credited outside the validation window. Surfaced so the
            // panel can warn that this credit was final for the merchant.
            'backdated' => (bool) $this->backdated,
            'occurred_at' => $this->occurred_at->toIso8601String(),
            'held_at' => $heldAt?->toIso8601String(),
            'held_by' => $this->held_by_type === null ? null : [
                'actor_type' => (string) $this->held_by_type,
                'actor_id' => $this->held_by_id === null ? null : (int) $this->held_by_id,
            ],
            // Whole days the review has been open. Null when the hold predates
            // the event log entirely — an age of "0 days" would be a lie.
            'age_days' => $heldAt === null ? null : (int) $heldAt->diffInDays($now, absolute: true),
            'pre_hold_state' => $preHoldState,
            // The derivation the release itself will run, shown before the
            // admin confirms: `starts_clock` is true exactly when releasing
            // puts the sale on the §7 15-day settlement clock, and
            // `resumes_clock` says that clock was ALREADY running when the
            // review opened — the release advances it by the frozen interval
            // instead of granting a fresh 15 days, so an already-overdue row
            // comes back just as overdue. Both come from the service's own
            // derivations; the panel never recomputes them.
            'release_target' => [
                'state' => $target->value,
                'starts_clock' => $target === TransactionState::PayableUnfunded,
                'resumes_clock' => $target === TransactionState::PayableUnfunded
                    && HoldReviewService::resumedClockStart($this->resource, $heldAt, $now) !== null,
            ],
        ];
    }

    private function heldAt(): ?CarbonImmutable
    {
        return $this->held_at === null ? null : CarbonImmutable::parse($this->held_at)->utc();
    }

    private function holdReasonCode(): ?string
    {
        $code = $this->hold_reason_code ?? $this->reason_code;

        return $code === null ? null : (string) $code;
    }

    /**
     * The panel/V1 lookup masking idiom: keep a short leading fragment, star
     * the rest, per name part — "Aisha Mohamed" → "Ais*** Moh***".
     */
    private static function maskName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        return implode(' ', array_map(
            fn (string $part): string => mb_substr($part, 0, 3).'***',
            $parts,
        ));
    }
}
