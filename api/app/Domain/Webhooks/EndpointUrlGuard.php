<?php

declare(strict_types=1);

namespace App\Domain\Webhooks;

/**
 * SSRF guard for §9.3 webhook endpoint URLs. The queue worker POSTs the
 * signed envelope wherever the endpoint row points, so the URL must never
 * be allowed to aim the platform's own network position at internal
 * services: cloud metadata (169.254.169.254), loopback, RFC1918 ranges,
 * link-local, or bare internal hostnames.
 *
 * Rules:
 *  - https only — the envelope carries customer transaction data; a signed
 *    body is tamper-evident, not confidential.
 *  - IP-literal hosts must be public: private, loopback, link-local and
 *    reserved ranges (v4 and v6) are refused.
 *  - localhost / *.localhost / *.local / *.internal names are refused.
 *  - Hostnames that RESOLVE to a non-public address are refused. A host
 *    that does not resolve at all is allowed through — it is not an SSRF
 *    vector (an unresolvable host cannot be connected to; if it later
 *    resolves privately, the send-time re-check below refuses delivery).
 *
 * Checked twice: at admin registration (422) and again immediately before
 * every delivery attempt (SendWebhook parks the delivery), so a DNS record
 * repointed at the internal network after registration is still refused.
 */
final class EndpointUrlGuard
{
    /**
     * @return string|null a human-readable refusal, or null when the URL is safe
     */
    public static function violation(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host']) || $parts['host'] === '') {
            return 'The url could not be parsed.';
        }

        if (strtolower($parts['scheme'] ?? '') !== 'https') {
            return 'The url must use https.';
        }

        $host = strtolower(trim($parts['host'], '.'));

        // parse_url keeps IPv6 literals bracketed.
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return 'The url must point at a public host.';
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::publicIp($host) ? null : 'The url must point at a public IP address.';
        }

        // A-record resolution; failure is allowed (see class doc).
        $resolved = gethostbynamel($host);

        if (is_array($resolved)) {
            foreach ($resolved as $ip) {
                if (! self::publicIp($ip)) {
                    return sprintf('The url host resolves to a non-public address (%s).', $ip);
                }
            }
        }

        return null;
    }

    private static function publicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
