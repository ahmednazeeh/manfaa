<?php

declare(strict_types=1);

namespace App\Domain\Marketplace;

use App\Domain\MerchantAccess\Permission;
use App\Domain\Notifications\NotificationService;
use App\Domain\Notifications\NotificationTemplateKey;
use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantKybDocument;
use App\Models\MerchantMarketplaceProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Becoming a vendor (PLAN-marketplace.md §9).
 *
 * Opt in → business type and profile sheet → KYB papers → an admin reads
 * them → live. A merchant who never starts has no row at all, which is the
 * point: marketplace is optional, and a store that only wants cashback
 * should carry no marketplace state whatsoever.
 */
final readonly class EnrolmentService
{
    public function __construct(private NotificationService $notifications) {}

    /** Every paper we require before a shop may sell. */
    public const array REQUIRED_DOCUMENTS = [
        'business_registration',
        'owner_id',
        'bank_letter',
    ];

    /**
     * Start, or update, an enrolment. Idempotent: a merchant editing their
     * business type before submitting is the same call.
     */
    public function enrol(Merchant $merchant, array $values): MerchantMarketplaceProfile
    {
        // Queried, never read off the relation. A caller holding a merchant
        // instance from earlier in the request would carry that instance's
        // idea of the profile — including a null loaded before the profile
        // existed — and this service must answer from the database.
        $profile = $this->profileOf($merchant) ?? new MerchantMarketplaceProfile([
            'merchant_id' => $merchant->getKey(),
            'enrolled_at' => CarbonImmutable::now(),
        ]);

        // A store already selling may adjust its sheet without falling back
        // into review — the papers were what needed reviewing, not the prep
        // time. Rejected stores return to pending when they resubmit.
        if (! $profile->exists || $profile->state === 'rejected') {
            $profile->state = 'pending_kyb';
            $profile->rejected_reason = null;
        }

        $profile->fill($values)->save();

        return $profile->refresh();
    }

    /**
     * Hand the enrolment to an admin. Refuses while a required paper is
     * missing — a queue full of half-finished applications wastes the
     * reviewer's attention, which is the scarce thing here.
     *
     * @return list<string> the missing document kinds; empty means submitted
     */
    public function submit(Merchant $merchant): array
    {
        $profile = $this->profileOf($merchant);

        if ($profile === null) {
            throw NotEnrolledException::forSubmit();
        }

        $held = $merchant->kybDocuments()
            ->whereIn('state', ['pending', 'accepted'])
            ->pluck('kind')
            ->all();

        $missing = array_values(array_diff(self::REQUIRED_DOCUMENTS, $held));

        if ($missing !== []) {
            return $missing;
        }

        $profile->forceFill([
            'state' => 'pending_kyb',
            'submitted_at' => CarbonImmutable::now(),
            'rejected_reason' => null,
        ])->save();

        return [];
    }

    /** An admin is satisfied: the shop may sell. */
    public function approve(Merchant $merchant, AdminUser $admin): MerchantMarketplaceProfile
    {
        $profile = $this->profileOf($merchant) ?? throw NotEnrolledException::forReview();

        DB::transaction(function () use ($merchant, $profile, $admin): void {
            $profile->forceFill([
                'state' => 'active',
                'approved_at' => CarbonImmutable::now(),
                'approved_by' => $admin->getKey(),
                'rejected_reason' => null,
            ])->save();

            $merchant->kybDocuments()
                ->where('state', 'pending')
                ->update([
                    'state' => 'accepted',
                    'reviewed_by' => $admin->getKey(),
                    'reviewed_at' => CarbonImmutable::now(),
                ]);
        });

        $this->notifications->sendToMerchantStaff(
            NotificationTemplateKey::MarketplaceApproved,
            $merchant->refresh(),
            ['store' => (string) $merchant->name],
            Permission::MarketplaceManage,
        );

        return $profile->refresh();
    }

    /**
     * Refused, with a reason the merchant can act on. The papers stay: a
     * store fixing one document should not re-upload the other three.
     */
    public function reject(Merchant $merchant, AdminUser $admin, string $reason): MerchantMarketplaceProfile
    {
        $profile = $this->profileOf($merchant) ?? throw NotEnrolledException::forReview();

        $profile->forceFill([
            'state' => 'rejected',
            'rejected_reason' => $reason,
            'approved_by' => null,
            'approved_at' => null,
        ])->save();

        $this->notifications->sendToMerchantStaff(
            NotificationTemplateKey::MarketplaceRejected,
            $merchant->refresh(),
            ['store' => (string) $merchant->name, 'reason' => $reason],
            Permission::MarketplaceManage,
        );

        return $profile->refresh();
    }

    /**
     * What the merchant still owes us, for the checklist on their screen.
     *
     * @return list<string>
     */
    public function missingDocuments(Merchant $merchant): array
    {
        $held = $merchant->kybDocuments()
            ->whereIn('state', ['pending', 'accepted'])
            ->pluck('kind')
            ->all();

        return array_values(array_diff(self::REQUIRED_DOCUMENTS, $held));
    }

    /**
     * The profile as the DATABASE holds it.
     *
     * Every read in this service goes through here rather than through
     * `$merchant->marketplace`, because a relation is a snapshot of whenever
     * it was first touched and enrolment moves within a single flow.
     */
    private function profileOf(Merchant $merchant): ?MerchantMarketplaceProfile
    {
        return $merchant->marketplace()->first();
    }

    /** Every kind we accept, required or not. */
    public function documentKinds(): array
    {
        return MerchantKybDocument::KINDS;
    }
}
