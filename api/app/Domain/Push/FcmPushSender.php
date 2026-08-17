<?php

declare(strict_types=1);

namespace App\Domain\Push;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Firebase Cloud Messaging, HTTP v1.
 *
 * FCM covers Android and — by forwarding to APNs — iOS, so one provider and
 * one credential serves both apps. Chosen for that, not for affection.
 *
 * NO NEW COMPOSER DEPENDENCY. The v1 API wants an OAuth2 bearer token, which
 * is a service-account JWT exchanged at Google's token endpoint. That is one
 * `openssl_sign` and one HTTP call, done below — cheaper than pulling
 * google/auth and its transitive tree into a live payments application for
 * two signatures an hour.
 *
 * Credentials live in the environment and never in the repository. Until
 * they are set, the container binds LogPushSender instead (see
 * PushServiceProvider) and nothing here runs.
 */
final class FcmPushSender implements PushSender
{
    private const string TOKEN_CACHE_KEY = 'fcm:access_token';

    private const string SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public function __construct(
        private readonly string $projectId,
        private readonly string $clientEmail,
        private readonly string $privateKey,
        private readonly string $tokenUri,
    ) {}

    public function send(string $deviceToken, string $title, string $body, array $data = []): void
    {
        try {
            $response = Http::withToken($this->accessToken())
                ->asJson()
                ->timeout(10)
                ->post("https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send", [
                    'message' => [
                        'token' => $deviceToken,
                        'notification' => ['title' => $title, 'body' => $body],
                        // Data values must be strings on FCM; anything else
                        // is rejected for the whole message.
                        'data' => array_map(strval(...), $data),
                        'android' => ['priority' => 'high'],
                        'apns' => ['headers' => ['apns-priority' => '10']],
                    ],
                ]);
        } catch (Throwable $exception) {
            throw new PushDeliveryException($exception->getMessage());
        }

        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        $reason = (string) $response->json('error.status', (string) $status);

        // A rejected CREDENTIAL, not a rejected device. The cached access
        // token is dead, and every worker shares that cache — without
        // eviction all three retries reuse it and the notification is lost.
        if ($status === 401 || $status === 403) {
            Cache::forget(self::TOKEN_CACHE_KEY);

            throw new PushDeliveryException("FCM refused the credential [{$status} {$reason}].");
        }

        // UNREGISTERED is FCM's verdict on the TOKEN: the app was
        // uninstalled or the registration rotated, and the row should go.
        //
        // INVALID_ARGUMENT is deliberately NOT in this set. It is a verdict
        // on the MESSAGE — an oversized payload, a bad field — and treating
        // it as a dead device would have one long rejection reason typed by
        // an admin delete every till in the store from push at once, each
        // device's job independently concluding its own token was bad.
        if ($status === 404 || $reason === 'UNREGISTERED') {
            throw PushDeliveryException::tokenRejected($reason);
        }

        throw new PushDeliveryException("FCM refused the message [{$status} {$reason}].");
    }

    /**
     * A service-account access token, cached just inside its OWN lifetime.
     *
     * Read and write rather than Cache::remember, so a 401/403 can evict it
     * (see send()) and so the TTL can come from the issuer instead of being
     * assumed.
     */
    private function accessToken(): string
    {
        if (is_string($cached = Cache::get(self::TOKEN_CACHE_KEY))) {
            return $cached;
        }

        return $this->mintAccessToken();
    }

    private function mintAccessToken(): string
    {
        $now = time();

        $claims = [
            'iss' => $this->clientEmail,
            'scope' => self::SCOPE,
            'aud' => $this->tokenUri,
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $jwt = self::base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR))
            .'.'.self::base64Url(json_encode($claims, JSON_THROW_ON_ERROR));

        $signature = '';

        if (! openssl_sign($jwt, $signature, $this->privateKey, OPENSSL_ALGO_SHA256)) {
            throw new PushDeliveryException('Could not sign the FCM service-account assertion.');
        }

        $response = Http::asForm()->timeout(10)->post($this->tokenUri, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt.'.'.self::base64Url($signature),
        ]);

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token)) {
            throw new PushDeliveryException('FCM refused the service-account assertion.');
        }

        // The issuer's OWN lifetime, minus a margin — assuming an hour would
        // serve a dead token for the remainder of the window whenever Google
        // grants a shorter one.
        $lifetime = (int) $response->json('expires_in', 3600);

        Cache::put(self::TOKEN_CACHE_KEY, $token, max(60, min($lifetime - 300, 3300)));

        return $token;
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
