<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Money\Percent;
use App\Domain\Tax\FeeTax;
use App\Domain\Tax\FeeTreatment;
use App\Domain\Tax\GstAnnouncement;
use App\Domain\Tax\TaxPolicy;
use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\TaxSetting;
use App\Rules\PercentRate;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * GST on the platform fee (owner, 2026-08-24). Read by any admin, WRITTEN by
 * a superadmin only — the same gating the platform's own bank accounts and
 * the transfer settings carry, and for the same reason: this one switch
 * changes what every merchant on the platform owes on every sale from the
 * moment it is thrown.
 *
 * THREE RULES THIS ENDPOINT ENFORCES, all of them the point of the feature:
 *
 *  1. ENABLING NEEDS AN IDENTITY. A GST-registered platform issues tax
 *     invoices, and an invoice that cannot name the registrant — TIN,
 *     business name, activity number — is not a tax invoice. Enabling
 *     without all three answers 422 rather than minting non-compliant
 *     records at till speed. The three may be filled in the SAME request
 *     that enables; it is the resulting row that must be complete.
 *  2. `enabled_at` IS STAMPED ON THE TRANSITION, not on every save. It is
 *     the instant the platform started charging tax, which is the first
 *     thing an auditor asks for.
 *  3. NOTHING HERE TOUCHES AN EXISTING TRANSACTION. Every row carries the
 *     GST rate and treatment it was priced under (`transactions.fee_gst_bp`
 *     / `fee_treatment`), and every report, settlement and journal reads
 *     that stamp. Enabling, re-rating and switching treatment price NEW
 *     sales only.
 *
 * Wire grammar (PLAN §1): the rate travels as `gst_rate_percent`, a
 * 2-decimal percent string. Basis points never appear in a request or a
 * response.
 */
final class TaxSettingsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return new JsonResponse([
            'data' => $this->payload(TaxSetting::current(), $this->isSuperadmin($request)),
        ]);
    }

    public function updateSettings(Request $request, GstAnnouncement $announcement): JsonResponse
    {
        $validated = $request->validate([
            'gst_enabled' => ['sometimes', 'boolean'],
            // 0%–20%: zero is legal (it is what a rate looks like while the
            // registration is pending), and the ceiling is the same
            // structural bound §4 puts on every other rate.
            'gst_rate_percent' => ['sometimes', PercentRate::between(0, Percent::MAX_BP)],
            'gst_tin' => ['sometimes', 'nullable', 'string', 'max:40'],
            'gst_business_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'gst_activity_number' => ['sometimes', 'nullable', 'string', 'max:40'],
            'fee_treatment' => ['sometimes', Rule::in(array_column(FeeTreatment::cases(), 'value'))],
        ]);

        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        $result = DB::transaction(function () use ($validated, $admin): array {
            /** @var TaxSetting $settings */
            $settings = TaxSetting::query()->lockForUpdate()->first() ?? TaxSetting::current();

            $wasEnabled = (bool) $settings->gst_enabled;

            if (array_key_exists('gst_rate_percent', $validated)) {
                $settings->gst_rate_bp = Percent::toBasisPointsWithin($validated['gst_rate_percent'], 0, Percent::MAX_BP);
            }

            foreach (['gst_enabled', 'gst_tin', 'gst_business_name', 'gst_activity_number', 'fee_treatment'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $settings->{$field} = $validated[$field];
                }
            }

            $enabling = ! $wasEnabled && (bool) $settings->gst_enabled;

            // The refusal, evaluated against the row as it WOULD be saved —
            // so a single request may supply the identity and the switch
            // together, and a request that supplies only the switch on an
            // anonymous row is refused.
            // Prose written for a person, in the words that sit above the
            // inputs — never the request keys, which is what the panel
            // renders this message verbatim into a destructive alert.
            if ($settings->gst_enabled && ($missing = $settings->missingIdentityLabels()) !== []) {
                abort(422, sprintf(
                    'GST cannot be enabled without the details a tax invoice must carry: %s. '
                        .'Fill them in first (or in the same request).',
                    implode(', ', $missing),
                ));
            }

            if ($enabling) {
                // The transition instant, stamped once. A later rate edit
                // leaves it exactly where it is; a re-enable after a
                // disablement re-stamps, because THAT is when charging
                // resumed.
                $settings->enabled_at = CarbonImmutable::now('UTC');
            }

            $settings->updated_by = $admin->getKey();
            $settings->save();

            return ['settings' => $settings, 'enabling' => $enabling];
        });

        /** @var TaxSetting $settings */
        $settings = $result['settings'];

        // The next sale prices under the new terms rather than up to a
        // cache TTL later.
        TaxPolicy::forget();

        if ($result['enabling'] === true) {
            // ONCE, on the transition — never on a rate edit.
            $announcement->announce(
                FeeTax::of((int) $settings->gst_rate_bp, $settings->fee_treatment),
                CarbonImmutable::parse($settings->enabled_at)->utc(),
            );
        }

        // The writer is a superadmin by route: they see what they just saved.
        return new JsonResponse(['data' => $this->payload($settings->refresh(), true)]);
    }

    private function isSuperadmin(Request $request): bool
    {
        $user = $request->user('admin');

        return $user instanceof AdminUser && $user->role === 'superadmin';
    }

    /**
     * The registration IDENTITY — TIN, registered business name, activity
     * number — is the platform's own tax registration, and only the role
     * that may write it needs to read it. A plain admin's screens use the
     * POLICY (is it on, at what rate, which treatment) and the readiness
     * flags; `GstOnFeesNote` on /settings/fee-tiers fetches this payload
     * into every admin's browser and uses exactly those. So the identity is
     * withheld rather than shipped to a screen that never renders it.
     *
     * Withheld as `null`, not omitted: the shape of the response must not
     * depend on who asked, or a client would have to guess which fields it
     * is allowed to expect.
     *
     * @return array<string, mixed>
     */
    private function payload(TaxSetting $settings, bool $withIdentity): array
    {
        return [
            'gst_enabled' => (bool) $settings->gst_enabled,
            // PLAN §1 wire format: percent string, never basis points.
            'gst_rate_percent' => Percent::format((int) $settings->gst_rate_bp),
            'gst_tin' => $withIdentity ? $settings->gst_tin : null,
            'gst_business_name' => $withIdentity ? $settings->gst_business_name : null,
            'gst_activity_number' => $withIdentity ? $settings->gst_activity_number : null,
            'fee_treatment' => $settings->fee_treatment->value,
            'fee_treatment_label' => $settings->fee_treatment->label(),
            'enabled_at' => $settings->enabled_at?->utc()->toIso8601String(),
            // What the switch is still waiting on, so a panel can say it
            // before the 422 does.
            'missing_identity_fields' => $settings->missingIdentityFields(),
            'can_enable' => $settings->missingIdentityFields() === [],
        ];
    }
}
