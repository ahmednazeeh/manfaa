<?php

declare(strict_types=1);

namespace App\Http\Controllers\Merchant;

use App\Domain\Connect\ConnectException;
use App\Domain\Connect\ConnectService;
use App\Domain\Credentials\VendorAbility;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The shopkeeper's side of "IsleBooks would like to … Authorise / Deny".
 *
 * Two calls: one to READ what is being asked (so the panel can draw the
 * question), one to ANSWER it. Reading is deliberately free of side effects
 * — a shopkeeper who opens the screen and closes it again has granted
 * nothing, and no row should exist to suggest otherwise.
 */
final class ConnectConsentController extends Controller
{
    public function __construct(private readonly ConnectService $connect) {}

    /** What the consent screen shows. Writes nothing. */
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => ['required', 'string', 'max:64'],
            'redirect_uri' => ['required', 'string', 'max:255'],
            'scope' => ['required', 'string', 'max:255'],
        ]);

        try {
            $vendor = $this->connect->client($validated['client_id']);
            $this->connect->assertRedirect($vendor, $validated['redirect_uri']);
            $abilities = $this->connect->abilities($vendor, $this->scopes($validated['scope']));
        } catch (ConnectException $e) {
            return $this->refuse($e);
        }

        $merchant = $this->merchant($request);
        $origin = $this->connect->originFor($vendor, $validated['redirect_uri']);

        // Asked before the question is drawn, so a store at its ceiling
        // reads why instead of pressing a button that cannot work.
        $atCap = null;

        try {
            $this->connect->assertCapacity($vendor, $merchant, $origin);
        } catch (ConnectException $e) {
            $atCap = $e->getMessage();
        }

        return new JsonResponse(['data' => [
            'application' => [
                'name' => $vendor->display_name ?: $vendor->name,
                'description' => $vendor->description,
                'website' => $vendor->website,
                'public_client' => $vendor->isPublicClient(),
            ],
            // For a public client the callback is whatever the plugin sent,
            // so the shopkeeper is shown exactly WHICH store is connecting
            // — "This will connect shop.example.mv" — before they approve.
            // Null for a confidential platform, whose callbacks were
            // registered by a superadmin.
            'callback_host' => $origin === null ? null : substr($origin, strlen('https://')),
            'store' => ['name' => $merchant->name],
            // The sentences the shopkeeper reads, in the order asked.
            'permissions' => array_map(fn (VendorAbility $a): array => [
                'ability' => $a->value,
                'line' => $a->consentLine(),
                'caution' => $a->consentCaution(),
            ], $abilities),
            // If they already connected this app, say so — pressing
            // Authorise again REPLACES, and that is worth knowing before
            // rather than after.
            'already_connected' => $this->connect
                ->liveCredentials($vendor, $merchant, $origin)
                ->isNotEmpty(),
            // Non-null means Authorise would fail; the string is what to
            // tell them to do about it.
            'blocked_reason' => $atCap,
        ]]);
    }

    /** Authorise. Returns where to send the browser next. */
    public function approve(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => ['required', 'string', 'max:64'],
            'redirect_uri' => ['required', 'string', 'max:255'],
            'scope' => ['required', 'string', 'max:255'],
            // Echoed back untouched so the platform can match its own
            // request and refuse a reply it did not start.
            'state' => ['sometimes', 'nullable', 'string', 'max:255'],
            'code_challenge' => ['required', 'string', 'min:43', 'max:128'],
            'code_challenge_method' => ['required', 'in:S256'],
        ]);

        /** @var MerchantUser $user */
        $user = $request->user('merchant');
        $merchant = $this->merchant($request);

        try {
            $vendor = $this->connect->client($validated['client_id']);
            $abilities = $this->connect->abilities($vendor, $this->scopes($validated['scope']));

            $code = $this->connect->approve(
                $vendor,
                $merchant,
                $user,
                $abilities,
                $validated['redirect_uri'],
                $validated['code_challenge'],
            );
        } catch (ConnectException $e) {
            return $this->refuse($e);
        }

        return new JsonResponse(['data' => [
            'redirect_to' => $this->callback($validated['redirect_uri'], [
                'code' => $code,
                'state' => $validated['state'] ?? null,
            ]),
        ]]);
    }

    /**
     * Deny. No code is minted; the platform is told plainly.
     *
     * Answered rather than ignored, because a platform left waiting cannot
     * tell refusal from a shopkeeper who wandered off, and will keep asking.
     */
    public function deny(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => ['required', 'string', 'max:64'],
            'redirect_uri' => ['required', 'string', 'max:255'],
            'state' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        try {
            $vendor = $this->connect->client($validated['client_id']);
            $this->connect->assertRedirect($vendor, $validated['redirect_uri']);
        } catch (ConnectException $e) {
            return $this->refuse($e);
        }

        return new JsonResponse(['data' => [
            'redirect_to' => $this->callback($validated['redirect_uri'], [
                'error' => 'access_denied',
                'state' => $validated['state'] ?? null,
            ]),
        ]]);
    }

    /** @return list<string> */
    private function scopes(string $scope): array
    {
        return array_values(array_filter(preg_split('/[\s,]+/', trim($scope)) ?: []));
    }

    /** @param  array<string, string|null>  $params */
    private function callback(string $redirectUri, array $params): string
    {
        $query = http_build_query(array_filter($params, fn (?string $v): bool => $v !== null && $v !== ''));

        return $redirectUri.(str_contains($redirectUri, '?') ? '&' : '?').$query;
    }

    private function refuse(ConnectException $e): JsonResponse
    {
        return new JsonResponse([
            'message' => $e->getMessage(),
            'code' => $e->errorCode,
        ], 422);
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
