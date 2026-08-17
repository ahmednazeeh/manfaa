<?php

declare(strict_types=1);

namespace App\Http\Controllers\Devices;

use App\Domain\Mobile\MobileAudience;
use App\Domain\Mobile\MobileTokenService;
use App\Http\Controllers\Controller;
use App\Http\Resources\MobileDeviceResource;
use App\Models\Customer;
use App\Models\MerchantUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The devices signed in to the mobile apps — list one, cut one off, cut them
 * all off.
 *
 * MOUNTED TWICE, ON PURPOSE. The same three actions are reachable from the
 * website (session cookie) and from the app itself (bearer token), because
 * the one moment this screen exists for is the moment the phone is GONE. A
 * revocation surface that can only be reached by presenting a token from the
 * device you are trying to revoke is not a revocation surface at all — it
 * was the shape of the first draft, and it made the lost-phone remedy
 * documented on revokeAll() unreachable by the customer, by support and by
 * an admin alike.
 *
 * One controller serves both because EnsureMobileToken sets the authenticated
 * user on the SESSION guard, so `$request->user($guard)` resolves identically
 * whichever way the caller arrived. Nothing here needs to know which it was.
 */
abstract class DevicesController extends Controller
{
    public function __construct(protected readonly MobileTokenService $tokens) {}

    abstract protected function audience(): MobileAudience;

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return response()->json([
            'data' => MobileDeviceResource::list(
                $this->tokens->devices($user, $this->audience()),
                $this->currentTokenId($user),
                $request,
            ),
        ]);
    }

    /**
     * Cut off ONE device.
     *
     * An id belonging to somebody else is a 404, not a 403: the service
     * scopes the lookup through the user's own relation, so a foreign id
     * matches nothing and there is nothing to be forbidden. That also keeps
     * the endpoint from confirming which token ids exist.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $this->user($request);

        if (! $this->tokens->revokeDevice($user, $this->audience(), $id)) {
            return response()->json(['message' => 'Device not found.'], 404);
        }

        return response()->json(null, 204);
    }

    /** Cut off every device — the lost-or-stolen-phone remedy. */
    public function destroyAll(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return response()->json([
            'data' => ['revoked' => $this->tokens->revokeAll($user, $this->audience())],
        ]);
    }

    protected function user(Request $request): Customer|MerchantUser
    {
        /** @var Customer|MerchantUser $user */
        $user = $request->user($this->audience()->guard());

        return $user;
    }

    /**
     * Null when the caller is a browser session — a session has a
     * TransientToken with no id, so no row is marked "this device".
     */
    private function currentTokenId(Customer|MerchantUser $user): ?int
    {
        $current = $user->currentAccessToken();

        return $current instanceof PersonalAccessToken ? $current->getKey() : null;
    }
}
