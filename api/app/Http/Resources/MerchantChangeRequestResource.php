<?php

namespace App\Http\Resources;

use App\Domain\Onboarding\MerchantLogo;
use App\Models\MerchantChangeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One queued store change, in the one shape every surface reads it: the
 * admin review queue, the merchant panel's "pending review" banner and the
 * merchant app's.
 *
 * `changes` is the whole point — a field-by-field before/after built from
 * the SNAPSHOT taken at submit time rather than from the live row, so a
 * reviewer opening a two-day-old request sees the comparison the merchant
 * actually proposed. `payload` and `snapshot` are published beside it for a
 * client that wants the raw halves.
 *
 * The logo never travels as a storage path. Both sides of a logo change are
 * URLs into the authorising preview route — an admin and the store's own
 * staff may fetch them, nobody else, exactly as an unapproved store's logo
 * is treated.
 *
 * @mixin MerchantChangeRequest
 */
class MerchantChangeRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = (array) ($this->payload ?? []);
        $snapshot = (array) ($this->snapshot ?? []);

        return [
            'id' => $this->id,
            'merchant_id' => $this->merchant_id,
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'status' => $this->status,
            'branch_id' => $this->branch_id,
            'branch_name' => $snapshot['name'] ?? null,
            'changes' => $this->changes($payload, $snapshot),
            'proposed' => $this->present($payload, 'proposed'),
            'current' => $this->present($snapshot, 'current'),
            'submitted_at' => $this->created_at?->toIso8601String(),
            'submitted_by' => $this->whenLoaded('submitter', fn (): array => [
                'id' => $this->submitter->id,
                'name' => $this->submitter->name,
            ]),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'reviewed_by' => $this->reviewed_by,
            'rejected_reason' => $this->rejected_reason,
            'merchant' => $this->whenLoaded('merchant', fn (): array => [
                'id' => $this->merchant->id,
                'name' => $this->merchant->name,
                'slug' => $this->merchant->slug,
                'status' => $this->merchant->status,
                'logo_url' => MerchantLogo::url($this->merchant->slug, $this->merchant->logo_path),
            ]),
        ];
    }

    /**
     * The before/after the reviewer decides on, one entry per proposed
     * field. A removal proposes no fields, so its diff is empty and the
     * `current` block is the branch being removed — which is the whole of
     * what that decision is about.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $snapshot
     * @return list<array<string, mixed>>
     */
    private function changes(array $payload, array $snapshot): array
    {
        $changes = [];

        foreach ($payload as $field => $to) {
            $changes[] = [
                'field' => $field === 'logo_path' ? 'logo' : $field,
                'from' => $field === 'logo_path' ? $this->logoUrl('current') : ($snapshot[$field] ?? null),
                'to' => $field === 'logo_path' ? $this->logoUrl('proposed') : $to,
            ];
        }

        return $changes;
    }

    /**
     * A half of the diff as the wire publishes it: the storage path swapped
     * for a URL, and the synthetic `id` the snapshot carries for branch
     * kinds is left to the reading surfaces, which lay a branch out by hand.
     *
     * Returned as an OBJECT, never a bare array, and that cast is the whole
     * reason this returns stdClass: a branch REMOVAL proposes nothing, so
     * `$values` is `[]` — and PHP writes an empty array as the JSON list
     * `[]`, not as `{}`. Every typed client reads these two keys as a
     * dictionary, so the one kind whose point is proposing nothing was the
     * one kind that failed to parse: the merchant panel's branch list, its
     * delete call and the admin review queue all refused a queued removal.
     * An assoc array encodes identically either way, so the cast costs
     * nothing and closes the hole at the source.
     *
     * @param  array<string, mixed>  $values
     */
    private function present(array $values, string $side): object
    {
        if (array_key_exists('logo_path', $values)) {
            $values['logo_url'] = $this->logoUrl($side);
            unset($values['logo_path']);
        }

        return (object) $values;
    }

    /**
     * The authorising preview URL for one side of a logo change, versioned
     * off the stored path so a replaced file is a different URL.
     */
    private function logoUrl(string $side): ?string
    {
        $source = $side === 'proposed' ? (array) $this->payload : (array) $this->snapshot;
        $path = $source['logo_path'] ?? null;

        if (! is_string($path) || $path === '') {
            return null;
        }

        return url(sprintf(
            '/api/change-requests/%d/logo?side=%s&v=%s',
            $this->id,
            $side,
            MerchantLogo::version($path),
        ));
    }

    /**
     * The queued-change envelope every gated write answers with — 202, so a
     * client can tell "saved" from "submitted for review" on the status line
     * alone, without inspecting the body.
     *
     * @param  array<string, mixed>  $extra  the instant half of a mixed write
     */
    public static function accepted(MerchantChangeRequest $request, array $extra = []): JsonResponse
    {
        return response()->json([
            'data' => [
                'status' => 'pending_review',
                'change_request' => (new self($request))->resolve(),
                ...$extra,
            ],
        ], 202);
    }
}
