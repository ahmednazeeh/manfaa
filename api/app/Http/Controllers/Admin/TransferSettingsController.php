<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\TransferProfile;
use App\Models\TransferSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The transfer endpoint, editable from the panel
 * (owner requirement 2026-08-19).
 *
 * The WireGuard peer does not exist yet, so none of this may be compiled in:
 * base URL, profile segment and debited account all have to change from a
 * screen the day the tunnel appears.
 *
 * The API key is NOT here and never will be. It lives in the environment,
 * because `x-api-key` is the whole of the upstream's authentication and a
 * secret readable from an admin session is a leaked bank.
 */
final class TransferSettingsController extends Controller
{
    public function index(): JsonResponse
    {
        $settings = TransferSetting::current();

        return new JsonResponse(['data' => [
            'auto_transfer_enabled' => $settings->auto_transfer_enabled,
            'auto_max_laari' => $settings->auto_max_laari,
            'profile_id' => $settings->profile_id,
            // Whether the KEY is present, never the key itself.
            'api_key_configured' => (string) config('services.transfer.api_key') !== '',
            'profiles' => TransferProfile::query()->orderBy('id')->get()->map(fn (TransferProfile $profile): array => [
                'id' => $profile->id,
                'name' => $profile->name,
                'base_url' => $profile->base_url,
                'segment' => $profile->segment,
                'from_account' => $profile->from_account,
                'endpoint' => $profile->endpoint(),
                // Dual control answers 200 with `pending_approval`: accepted
                // and parked, never to be re-sent.
                'dual_control' => $profile->dual_control,
                'active' => $profile->active,
                'is_default' => $profile->is_default,
            ])->values(),
        ]]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'auto_transfer_enabled' => ['sometimes', 'boolean'],
            'auto_max_laari' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'profile_id' => ['sometimes', 'nullable', 'integer', Rule::exists('transfer_profiles', 'id')],
        ]);

        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        $settings = TransferSetting::current();
        $settings->fill($validated);
        $settings->updated_by = $admin->getKey();
        $settings->save();

        return $this->index();
    }

    public function updateProfile(Request $request, TransferProfile $profile): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            // http://10.99.0.1:3005 today; whatever the tunnel turns out to
            // be tomorrow.
            'base_url' => ['sometimes', 'string', 'max:255', 'starts_with:http://,https://'],
            'segment' => ['sometimes', 'string', 'max:60'],
            // Must be one of THAT profile's own accounts upstream; we cannot
            // check that from here, so the panel shows the list and an
            // operator picks. Ignored entirely on /bml/transfer.
            'from_account' => ['sometimes', 'nullable', 'string', 'max:40'],
            'dual_control' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        DB::transaction(function () use ($profile, $validated, $admin): void {
            $profile->fill($validated);
            $profile->updated_by = $admin->getKey();
            $profile->save();

            // Exactly one default, always — "whichever row happened to be
            // first" is not a thing to send money through.
            if ($profile->is_default) {
                TransferProfile::query()
                    ->whereKeyNot($profile->getKey())
                    ->update(['is_default' => false]);
            }
        });

        return $this->index();
    }
}
