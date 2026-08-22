<?php

declare(strict_types=1);

namespace App\Domain\Connect;

use RuntimeException;

/**
 * A refused connect handshake. `$errorCode` follows RFC 6749 §5.2 where one
 * fits, so a platform's existing OAuth client library reads it unaided.
 */
final class ConnectException extends RuntimeException
{
    private function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function unknownClient(): self
    {
        // Identical whether the client_id is unknown, unregistered for
        // connect, or mistyped: the authorize screen must not become a way
        // to enumerate which platforms exist.
        return new self('invalid_client', 'That application is not registered with Manfaa.');
    }

    public static function badRedirect(): self
    {
        return new self('invalid_request', 'That redirect address is not registered for this application.');
    }

    public static function unknownScope(string $ability): self
    {
        return new self('invalid_scope', sprintf('"%s" is not something an application can ask for.', $ability));
    }

    public static function scopeNotPermitted(string $ability): self
    {
        // The platform is real but asking beyond what a superadmin approved
        // it for. Said plainly, because this one is the platform's bug to
        // fix rather than the shopkeeper's problem.
        return new self(
            'invalid_scope',
            sprintf('This application is not approved to request "%s".', $ability),
        );
    }

    public static function noScope(): self
    {
        return new self('invalid_scope', 'An application must ask for at least one permission.');
    }

    public static function badCode(): self
    {
        // One message for expired, spent, unknown and wrong-client. Telling
        // them apart would let whoever holds a stolen code learn why it
        // failed and try the next thing.
        return new self('invalid_grant', 'That authorisation code is not valid.');
    }

    public static function badVerifier(): self
    {
        return new self('invalid_grant', 'The code verifier does not match the challenge.');
    }

    public static function badSecret(): self
    {
        return new self('invalid_client', 'That application could not be authenticated.');
    }

    /**
     * A public client sent a secret it cannot legitimately hold. A plugin
     * that believes it has one is misconfigured — or is not the plugin.
     */
    public static function secretFromPublicClient(): self
    {
        return new self('invalid_client', 'This application is a public client and must not send a client secret.');
    }

    /** A public client's callback failed the URL policy; the reason is the guard's own sentence. */
    public static function badPublicRedirect(string $why): self
    {
        return new self('invalid_request', 'The redirect_uri is not acceptable: '.$why);
    }

    /**
     * The store is already holding as many live credentials as it may.
     *
     * Raised when the shopkeeper presses Authorise rather than when the
     * platform later exchanges the code: failing at exchange time would
     * leave them believing they had connected, and the platform with an
     * error it cannot do anything about.
     */
    public static function storeAtCredentialCap(int $cap): self
    {
        return new self(
            'access_denied',
            sprintf(
                'This store already has %d active API credentials, the maximum. Revoke one you no longer use in Settings, then connect this application.',
                $cap,
            ),
        );
    }
}
