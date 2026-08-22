<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosVendor extends Model
{
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            // Matched exactly — never as a prefix or a pattern.
            'redirect_uris' => 'array',
            // The ceiling on what this platform may ever ask for.
            'allowed_abilities' => 'array',
            'connect_enabled' => 'boolean',
            // No secret, no registered callbacks: PKCE and the shopkeeper's
            // consent are the proof (owner decision 2026-08-22).
            'public_client' => 'boolean',
        ];
    }

    /**
     * Registered, switched on, and — for a confidential client — holding a
     * secret it can prove. A public client proves itself with PKCE alone.
     */
    public function canConnect(): bool
    {
        return $this->connect_enabled
            && $this->client_id !== null
            && ($this->isPublicClient() || $this->client_secret_hash !== null)
            && $this->integration_status !== 'revoked';
    }

    /** Software that cannot keep a secret: a plugin on somebody else's server. */
    public function isPublicClient(): bool
    {
        return (bool) $this->public_client;
    }

    public function apiCredentials(): HasMany
    {
        return $this->hasMany(ApiCredential::class);
    }
}
