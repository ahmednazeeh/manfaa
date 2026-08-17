<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * One signed-in device, as the customer or staff member sees it.
 *
 * Carries NOTHING that could help someone use the credential: no token, no
 * hash, no abilities. A device list is a security screen, and the only
 * questions it has to answer are "is that me?" and "which one do I kill?".
 *
 * @property PersonalAccessToken $resource
 */
class MobileDeviceResource extends JsonResource
{
    /**
     * The current token id is PASSED IN rather than read off the request.
     *
     * This resource is rendered for two different callers: the app, where
     * the request carries a PersonalAccessToken, and the website, where it
     * carries a session. Resolving "which guard is this?" inside a resource
     * would be a guess — and guessing wrong on the default guard (`admin`)
     * would silently mark every device as not-current.
     */
    public function __construct($resource, private readonly ?int $currentTokenId = null)
    {
        parent::__construct($resource);
    }

    /**
     * @param  iterable<PersonalAccessToken>  $devices
     * @return list<array<string, mixed>>
     */
    public static function list(iterable $devices, ?int $currentTokenId, Request $request): array
    {
        $rendered = [];

        foreach ($devices as $device) {
            $rendered[] = (new self($device, $currentTokenId))->toArray($request);
        }

        return $rendered;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $token = $this->resource;

        return [
            'id' => $token->getKey(),
            // The name is stored as "<audience>: <device>" so a support
            // conversation can tell the two apps apart; the owner only needs
            // the half they typed.
            'device_name' => self::deviceName((string) $token->name),
            'signed_in_at' => $token->created_at?->toIso8601ZuluString(),
            'last_used_at' => $token->last_used_at?->toIso8601ZuluString(),
            'expires_at' => $token->expires_at?->toIso8601ZuluString(),
            // True only for a request that arrived ON this device. From the
            // website every device reads as revocable — which is right: the
            // website is where you go when the phone is gone.
            'is_current_device' => $this->currentTokenId !== null
                && $this->currentTokenId === $token->getKey(),
        ];
    }

    private static function deviceName(string $stored): string
    {
        $separator = strpos($stored, ': ');

        return $separator === false ? $stored : substr($stored, $separator + 2);
    }
}
