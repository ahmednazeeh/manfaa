<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Approvals\ChangeRequestService;
use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantChangeRequest;

/**
 * MR9: a live store's public claims queue for admin review, so a test that
 * used to assert "the branch is now named X" has to walk the store's change
 * through a reviewer first.
 *
 * A class rather than the Pest helpers the rest of the suite uses, for the
 * reason Tests\Support\TransferSheet gives: several test files need this and
 * a global function may only be declared once.
 */
final class Approvals
{
    /** The store's oldest pending request — what it just submitted. */
    public static function pending(Merchant $merchant): MerchantChangeRequest
    {
        return MerchantChangeRequest::query()
            ->where('merchant_id', $merchant->getKey())
            ->where('status', MerchantChangeRequest::PENDING)
            ->orderBy('id')
            ->firstOrFail();
    }

    /** Approves it, as the superadmin the real queue requires. */
    public static function approve(MerchantChangeRequest $request): MerchantChangeRequest
    {
        return app(ChangeRequestService::class)->approve($request, self::superadmin());
    }

    /** Approves everything the store has waiting, oldest first. */
    public static function approveAll(Merchant $merchant): void
    {
        $admin = self::superadmin();
        $service = app(ChangeRequestService::class);

        $pending = MerchantChangeRequest::query()
            ->where('merchant_id', $merchant->getKey())
            ->where('status', MerchantChangeRequest::PENDING)
            ->orderBy('id')
            ->get();

        foreach ($pending as $request) {
            $service->approve($request, $admin);
        }
    }

    public static function superadmin(): AdminUser
    {
        return AdminUser::factory()->create(['role' => 'superadmin']);
    }
}
