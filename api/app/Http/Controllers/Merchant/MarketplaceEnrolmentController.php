<?php

declare(strict_types=1);

namespace App\Http\Controllers\Merchant;

use App\Domain\Marketplace\EnrolmentService;
use App\Domain\Marketplace\NotEnrolledException;
use App\Domain\Money\Percent;
use App\Domain\Platform\PlatformConfig;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantKybDocument;
use App\Models\MerchantUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The merchant's own view of becoming a vendor
 * (PLAN-marketplace.md §9, MP1).
 *
 * Every route here sits behind the kill switch, so a platform that has not
 * launched the marketplace does not appear to have one — not even to a
 * merchant poking at URLs.
 */
final class MarketplaceEnrolmentController extends Controller
{
    public function __construct(private readonly EnrolmentService $enrolment) {}

    /** Where this store stands, and what it still owes us. */
    public function show(Request $request, PlatformConfig $config): JsonResponse
    {
        $merchant = $this->merchant($request);
        $profile = $merchant->marketplace;

        return new JsonResponse(['data' => [
            // `not_enrolled` for a store that never asked — the absence of a
            // row is a state, and the client should not have to know that.
            'state' => $profile?->state ?? 'not_enrolled',
            'business_type' => $profile?->business_type,
            'fulfilment' => $profile?->fulfilment,
            'prep_time_min' => $profile?->prep_time_min,
            'prep_time_max' => $profile?->prep_time_max,
            'rejected_reason' => $profile?->rejected_reason,
            'submitted_at' => $profile?->submitted_at?->toIso8601String(),
            'approved_at' => $profile?->approved_at?->toIso8601String(),
            // The rate this store will actually be charged: its own override
            // if it has one, otherwise the platform default. Percent on the
            // wire, never basis points (§1 wire format).
            'order_fee_percent' => Percent::format(
                $profile?->order_fee_bp ?? $config->marketplaceFeeBp(),
            ),
            'required_documents' => EnrolmentService::REQUIRED_DOCUMENTS,
            'missing_documents' => $this->enrolment->missingDocuments($merchant),
            'documents' => $merchant->kybDocuments()->orderBy('kind')->get()->map(fn (MerchantKybDocument $doc): array => [
                'id' => $doc->id,
                'kind' => $doc->kind,
                // The stored PATH is never published — see download().
                'original_name' => $doc->original_name,
                'size' => $doc->size,
                'state' => $doc->state,
                'reject_reason' => $doc->reject_reason,
                'uploaded_at' => $doc->created_at?->toIso8601String(),
            ])->values(),
        ]]);
    }

    /** Opt in, or edit the profile sheet before submitting. */
    public function enrol(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'business_type' => ['required', Rule::in(['sole_prop', 'partnership', 'pvt_ltd', 'cooperative'])],
            'fulfilment' => ['required', Rule::in(['delivery', 'pickup', 'both'])],
            'prep_time_min' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'prep_time_max' => ['nullable', 'integer', 'min:0', 'max:1440', 'gte:prep_time_min'],
        ]);

        $profile = $this->enrolment->enrol($this->merchant($request), $validated);

        return new JsonResponse(['data' => ['state' => $profile->state]], 200);
    }

    /**
     * Upload one KYB paper. Replaces the document of that kind if one is
     * already held — a merchant fixing a blurry photo should not have to
     * find a delete button first.
     */
    public function upload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::in(MerchantKybDocument::KINDS)],
            // Identity papers arrive as photos or PDFs. 8 MB is generous for
            // a phone camera and far below anything that would trouble the
            // disk.
            'file' => ['required', 'file', 'extensions:jpg,jpeg,png,webp,pdf', 'max:8192'],
        ]);

        $merchant = $this->merchant($request);
        $file = $request->file('file');

        // A uuid filename, on the PRIVATE disk. The path is never returned
        // to any client and never derivable from a merchant id.
        $path = $file->storeAs(
            sprintf('kyb/%d', $merchant->getKey()),
            Str::uuid().'.'.$file->getClientOriginalExtension(),
            MerchantKybDocument::DISK,
        );

        $existing = $merchant->kybDocuments()->where('kind', $validated['kind'])->first();

        if ($existing !== null) {
            // Delete the superseded file, not just its row: an identity
            // document nobody can reach is still a document we are holding.
            Storage::disk(MerchantKybDocument::DISK)->delete($existing->path);
        }

        $document = $merchant->kybDocuments()->updateOrCreate(
            ['kind' => $validated['kind']],
            [
                'path' => $path,
                'original_name' => (string) $file->getClientOriginalName(),
                'mime' => (string) $file->getMimeType(),
                'size' => (int) $file->getSize(),
                // A replaced paper is unreviewed again, whatever it was.
                'state' => 'pending',
                'reject_reason' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
            ],
        );

        return new JsonResponse(['data' => [
            'id' => $document->id,
            'kind' => $document->kind,
            'original_name' => $document->original_name,
            'state' => $document->state,
        ]], 201);
    }

    /** The merchant reading back their own paper. */
    public function download(Request $request, int $document)
    {
        $merchant = $this->merchant($request);
        $doc = $merchant->kybDocuments()->whereKey($document)->firstOrFail();

        abort_unless(Storage::disk(MerchantKybDocument::DISK)->exists($doc->path), 404);

        return Storage::disk(MerchantKybDocument::DISK)->download($doc->path, $doc->original_name);
    }

    /** Hand it to a reviewer. 422 names what is still missing. */
    public function submit(Request $request): JsonResponse
    {
        try {
            $missing = $this->enrolment->submit($this->merchant($request));
        } catch (NotEnrolledException $e) {
            return new JsonResponse([
                'message' => $e->getMessage(),
                'code' => 'not_enrolled',
            ], 409);
        }

        if ($missing !== []) {
            return new JsonResponse([
                'message' => 'Some documents are still missing.',
                'code' => 'kyb_incomplete',
                'meta' => ['missing' => $missing],
            ], 422);
        }

        return new JsonResponse(['data' => ['state' => 'pending_kyb']]);
    }

    private function merchant(Request $request): Merchant
    {
        $user = $request->user('merchant');
        abort_unless($user instanceof MerchantUser, 403);

        $merchant = $user->merchant;
        abort_if($merchant === null, 403);

        return $merchant;
    }
}
