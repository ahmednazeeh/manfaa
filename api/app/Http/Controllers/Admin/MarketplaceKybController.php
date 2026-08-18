<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Marketplace\EnrolmentService;
use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantKybDocument;
use App\Models\MerchantMarketplaceProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * The admin's KYB queue (PLAN-marketplace.md §9, MP1).
 *
 * Reading an application is ordinary admin work; approving one is
 * superadmin, the same tier as approving a store — letting a business sell
 * to the public on our platform is the same class of decision.
 */
final class MarketplaceKybController extends Controller
{
    public function __construct(private readonly EnrolmentService $enrolment) {}

    /** The queue: applications waiting, oldest first — the fair order. */
    public function index(Request $request): JsonResponse
    {
        $state = (string) $request->query('state', 'pending_kyb');

        $profiles = MerchantMarketplaceProfile::query()
            ->with('merchant:id,name,slug,status,contact_phone')
            ->when($state !== 'all', fn ($query) => $query->where('state', $state))
            ->orderByRaw('submitted_at is null, submitted_at asc')
            ->get();

        return new JsonResponse([
            'data' => $profiles->map($this->present(...))->values(),
        ]);
    }

    public function show(Merchant $merchant): JsonResponse
    {
        $profile = $merchant->marketplace;
        abort_if($profile === null, 404);

        return new JsonResponse(['data' => [
            ...$this->present($profile->load('merchant:id,name,slug,status,contact_phone')),
            'documents' => $merchant->kybDocuments()->orderBy('kind')->get()->map(fn (MerchantKybDocument $doc): array => [
                'id' => $doc->id,
                'kind' => $doc->kind,
                'original_name' => $doc->original_name,
                'mime' => $doc->mime,
                'size' => $doc->size,
                'state' => $doc->state,
                'reject_reason' => $doc->reject_reason,
                'uploaded_at' => $doc->created_at?->toIso8601String(),
            ])->values(),
            'missing_documents' => $this->enrolment->missingDocuments($merchant),
        ]]);
    }

    /** Stream one paper to the reviewer. Never a public URL. */
    public function download(Merchant $merchant, int $document)
    {
        $doc = $merchant->kybDocuments()->whereKey($document)->firstOrFail();

        abort_unless(Storage::disk(MerchantKybDocument::DISK)->exists($doc->path), 404);

        return Storage::disk(MerchantKybDocument::DISK)->download($doc->path, $doc->original_name);
    }

    public function approve(Request $request, Merchant $merchant): JsonResponse
    {
        $profile = $merchant->marketplace;
        abort_if($profile === null, 404);

        if ($profile->state === 'active') {
            return new JsonResponse([
                'message' => 'This store is already selling on the marketplace.',
                'code' => 'already_active',
            ], 409);
        }

        // Never approve an application that is not actually complete — the
        // merchant would go live owing us a document nobody would chase.
        $missing = $this->enrolment->missingDocuments($merchant);

        if ($missing !== []) {
            return new JsonResponse([
                'message' => 'This application is missing required documents.',
                'code' => 'kyb_incomplete',
                'meta' => ['missing' => $missing],
            ], 422);
        }

        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        return new JsonResponse(['data' => $this->present(
            $this->enrolment->approve($merchant, $admin)->load('merchant:id,name,slug,status,contact_phone'),
        )]);
    }

    public function reject(Request $request, Merchant $merchant): JsonResponse
    {
        $validated = $request->validate([
            // A refusal the merchant cannot act on is worse than none: they
            // will resubmit the same papers and wait again.
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $profile = $merchant->marketplace;
        abort_if($profile === null, 404);

        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        return new JsonResponse(['data' => $this->present(
            $this->enrolment->reject($merchant, $admin, $validated['reason'])
                ->load('merchant:id,name,slug,status,contact_phone'),
        )]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(MerchantMarketplaceProfile $profile): array
    {
        return [
            'merchant_id' => $profile->merchant_id,
            'merchant_name' => $profile->merchant?->name,
            'merchant_slug' => $profile->merchant?->slug,
            'merchant_status' => $profile->merchant?->status,
            'contact_phone' => $profile->merchant?->contact_phone,
            'state' => $profile->state,
            'business_type' => $profile->business_type,
            'fulfilment' => $profile->fulfilment,
            'prep_time_min' => $profile->prep_time_min,
            'prep_time_max' => $profile->prep_time_max,
            'rejected_reason' => $profile->rejected_reason,
            'submitted_at' => $profile->submitted_at?->toIso8601String(),
            'approved_at' => $profile->approved_at?->toIso8601String(),
        ];
    }
}
