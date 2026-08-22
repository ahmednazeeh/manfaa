<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Credentials\CredentialService;
use App\Domain\Credentials\VendorAbility;
use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\ApiCredential;
use App\Models\PosVendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Registering a PLATFORM that may connect to many merchants
 * (owner decision 2026-08-19).
 *
 * Superadmin only, and that is the point of the whole design. A platform
 * credential lets its holder put "IsleBooks would like to …" in front of
 * any shopkeeper on Manfaa; the merchant still decides, but the ASKING is a
 * privilege. Without this gate anyone could write a consent screen naming
 * themselves.
 *
 * A developer who does not have one is not stuck — they use the existing
 * per-merchant key, which the merchant issues themselves and which reaches
 * exactly one shop.
 *
 * The secret is shown ONCE, at registration or rotation, and stored hashed.
 */
final class PlatformClientController extends Controller
{
    public function index(): JsonResponse
    {
        return new JsonResponse([
            'data' => PosVendor::query()
                ->orderBy('name')
                ->get()
                ->map(fn (PosVendor $vendor): array => $this->present($vendor))
                ->values(),
            'meta' => [
                // What a superadmin may tick, with the sentence each one
                // shows the shopkeeper — so the person granting it reads
                // exactly what the merchant will read.
                'abilities' => array_map(
                    fn (VendorAbility $a): array => [
                        'ability' => $a->value,
                        'consent_line' => $a->consentLine(),
                        'caution' => $a->consentCaution(),
                    ],
                    VendorAbility::cases(),
                ),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());

        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        // A public client (a plugin on merchants' own servers) gets no
        // secret — there is nowhere it could keep one — and no callback
        // list: its callback arrives with each request and the merchant's
        // consent is the registration. PKCE is its proof.
        $public = (bool) ($validated['public_client'] ?? false);
        $secret = $public ? null : Str::random(48);

        $vendor = PosVendor::query()->create([
            'name' => $validated['name'],
            'display_name' => $validated['display_name'] ?? $validated['name'],
            'description' => $validated['description'] ?? null,
            'website' => $validated['website'] ?? null,
            'contact' => $validated['contact'] ?? null,
            'integration_status' => 'active',
            'client_id' => 'mfa_'.Str::lower(Str::random(24)),
            'client_secret_hash' => $secret === null ? null : Hash::make($secret),
            'redirect_uris' => $public ? null : $validated['redirect_uris'],
            'public_client' => $public,
            'allowed_abilities' => $validated['allowed_abilities'],
            // Registering a platform and letting it loose on merchants are
            // two decisions. This one is the first.
            'connect_enabled' => $validated['connect_enabled'] ?? false,
            'registered_by' => $admin->getKey(),
        ]);

        return new JsonResponse([
            'data' => $this->present($vendor) + [
                // The ONLY time this is ever readable. Null for a public client.
                'client_secret' => $secret,
            ],
        ], 201);
    }

    public function update(Request $request, PosVendor $vendor): JsonResponse
    {
        $validated = $request->validate($this->rules(creating: false));

        $vendor->fill($validated)->save();

        return new JsonResponse(['data' => $this->present($vendor->refresh())]);
    }

    /**
     * A new secret, and every token the old one ever produced is cut.
     *
     * Rotation happens because a secret leaked. Leaving the grants alive
     * would mean rotating changed nothing for whoever already holds them.
     */
    public function rotate(Request $request, PosVendor $vendor): JsonResponse
    {
        if ($vendor->isPublicClient()) {
            // Nothing to rotate. Cutting a public client's grants is done
            // per merchant (they revoke) or by pausing the client.
            return new JsonResponse([
                'message' => 'This is a public client: it has no secret to rotate.',
                'code' => 'public_client',
            ], 409);
        }

        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        $secret = Str::random(48);
        $vendor->forceFill(['client_secret_hash' => Hash::make($secret)])->save();

        $cut = 0;

        foreach (ApiCredential::query()
            ->where('pos_vendor_id', $vendor->getKey())
            ->whereNull('revoked_at')
            ->get() as $credential) {
            app(CredentialService::class)->revoke($credential, $admin);
            $cut++;
        }

        return new JsonResponse([
            'data' => $this->present($vendor->refresh()) + [
                'client_secret' => $secret,
                'connections_revoked' => $cut,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function rules(bool $creating = true): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:120'],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'website' => ['sometimes', 'nullable', 'url', 'max:255'],
            'contact' => ['sometimes', 'nullable', 'string', 'max:255'],

            // Decided at registration and never flipped: a confidential
            // client's grants were issued against a secret; a public one's
            // against a consent screen that named the connecting store.
            'public_client' => [$creating ? 'sometimes' : 'prohibited', 'boolean'],

            // Exact callbacks. HTTPS only, because an authorization code
            // must never cross the wire in clear. A public client has none.
            'redirect_uris' => [
                $creating ? Rule::requiredIf(fn () => ! request()->boolean('public_client')) : 'sometimes',
                'nullable', 'array', 'max:10',
            ],
            'redirect_uris.*' => ['required', 'url', 'starts_with:https://', 'max:255'],

            'allowed_abilities' => [$required, 'array', 'min:1'],
            'allowed_abilities.*' => ['required', Rule::in(VendorAbility::values())],

            'connect_enabled' => ['sometimes', 'boolean'],
            'integration_status' => ['sometimes', Rule::in(['active', 'paused', 'revoked'])],
        ];
    }

    /** @return array<string, mixed> */
    private function present(PosVendor $vendor): array
    {
        return [
            'id' => $vendor->id,
            'name' => $vendor->name,
            'display_name' => $vendor->display_name,
            'description' => $vendor->description,
            'website' => $vendor->website,
            'contact' => $vendor->contact,
            'client_id' => $vendor->client_id,
            'public_client' => $vendor->isPublicClient(),
            // Never the secret, not even hashed.
            'has_secret' => $vendor->client_secret_hash !== null,
            'redirect_uris' => $vendor->redirect_uris ?? [],
            'allowed_abilities' => $vendor->allowed_abilities ?? [],
            'connect_enabled' => (bool) $vendor->connect_enabled,
            'integration_status' => $vendor->integration_status,
            'connections' => ApiCredential::query()
                ->where('pos_vendor_id', $vendor->getKey())
                ->whereNull('revoked_at')
                ->count(),
        ];
    }
}
