<?php

declare(strict_types=1);

namespace Manfaa\Cashback\Api;

use Manfaa\Cashback\Support\Crypto;
use Manfaa\Cashback\Support\Log;
use Manfaa\Cashback\Support\Options;

/**
 * The Manfaa `/v1` API over WordPress's HTTP client: bearer token,
 * Idempotency-Key on writes, and the error envelope turned into
 * {@see ApiException}. Everything the plugin sends goes through here, so the
 * log and the timeouts are in one place.
 */
class Client
{
    public const TOKEN_OPTION = 'manfaa_cashback_token';

    private const TIMEOUT = 15;

    public function __construct(private readonly ?string $token = null) {}

    public static function fromSettings(): self
    {
        return new self(self::storedToken());
    }

    public static function storedToken(): ?string
    {
        return Crypto::decrypt(get_option(self::TOKEN_OPTION) ?: null);
    }

    public static function storeToken(?string $token): void
    {
        if ($token === null) {
            delete_option(self::TOKEN_OPTION);

            return;
        }

        update_option(self::TOKEN_OPTION, Crypto::encrypt($token), false);
    }

    public static function tokenKeyMismatch(): bool
    {
        return Crypto::keyMismatch(get_option(self::TOKEN_OPTION) ?: null);
    }

    public function connected(): bool
    {
        return $this->token !== null && $this->token !== '';
    }

    /** @return array<string, mixed> */
    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, null, $query);
    }

    /** @return array<string, mixed> */
    public function post(string $path, array $body, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', $path, $body, [], $idempotencyKey);
    }

    /** @return array<string, mixed> */
    public function delete(string $path): array
    {
        return $this->request('DELETE', $path, null);
    }

    /**
     * Unauthenticated POST — the connect token exchange.
     *
     * @return array<string, mixed>
     */
    public static function postPublic(string $path, array $body): array
    {
        return (new self(null))->request('POST', $path, $body);
    }

    /**
     * @param  array<string, mixed>|null  $body  sent as JSON, byte-identical to what the caller froze
     * @return array<string, mixed>  decoded body, plus `_status` and `_headers`
     */
    private function request(string $method, string $path, ?array $body, array $query = [], ?string $idempotencyKey = null, ?string $rawBody = null): array
    {
        $url = Options::apiBase().'/'.ltrim($path, '/');

        if ($query !== []) {
            $url = add_query_arg(array_map('strval', $query), $url);
        }

        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => 'manfaa-cashback/'.MANFAA_CASHBACK_VERSION.' WooCommerce/'.(defined('WC_VERSION') ? WC_VERSION : '?'),
        ];

        if ($this->token !== null) {
            $headers['Authorization'] = 'Bearer '.$this->token;
        }

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $args = ['method' => $method, 'headers' => $headers, 'timeout' => self::TIMEOUT];

        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $args['headers'] = $headers;
            $args['body'] = $rawBody ?? wp_json_encode($body, JSON_UNESCAPED_SLASHES);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            Log::warning('Manfaa request failed before an answer', ['method' => $method, 'path' => $path, 'error' => $response->get_error_message()]);
            throw ApiException::transport($response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        $decoded = is_array($decoded) ? $decoded : [];
        $retryAfter = wp_remote_retrieve_header($response, 'retry-after');

        if ($status >= 200 && $status < 300) {
            $decoded['_status'] = $status;
            $decoded['_replay'] = strtolower((string) wp_remote_retrieve_header($response, 'idempotency-replay')) === 'true';

            return $decoded;
        }

        // Two shapes: the envelope {error:{code,message}} on /v1, the bare
        // {message} on auth-layer 401/403, and the OAuth {error} on connect.
        $code = $decoded['error']['code'] ?? (is_string($decoded['error'] ?? null) ? $decoded['error'] : null)
            ?? match ($status) { 401 => 'unauthorized', 403 => 'forbidden_ability', 429 => 'rate_limited', default => 'http_'.$status };
        $message = $decoded['error']['message'] ?? $decoded['error_description'] ?? $decoded['message'] ?? ('HTTP '.$status);

        Log::info('Manfaa answered '.$status, ['method' => $method, 'path' => $path, 'code' => $code]);

        throw new ApiException($status, (string) $code, (string) $message, $decoded, $retryAfter !== '' ? (int) $retryAfter : null);
    }

    /** PATCH, frozen body — the amend. */
    public function patchRaw(string $path, string $rawJson, string $idempotencyKey): array
    {
        $decoded = json_decode($rawJson, true);

        return $this->request('PATCH', $path, is_array($decoded) ? $decoded : [], [], $idempotencyKey, $rawJson);
    }

    /**
     * POST with a body frozen as raw JSON — re-sent byte-identical on every
     * retry so the idempotency key never meets a different body.
     *
     * @return array<string, mixed>
     */
    public function postRaw(string $path, string $rawJson, string $idempotencyKey): array
    {
        $decoded = json_decode($rawJson, true);

        return $this->request('POST', $path, is_array($decoded) ? $decoded : [], [], $idempotencyKey, $rawJson);
    }
}
