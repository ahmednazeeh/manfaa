<?php

declare(strict_types=1);

namespace App\Domain\Credentials;

use App\Models\ApiCredential;

/**
 * The result of issuing a vendor credential. `$plainTextToken` exists only
 * here, in memory, on the request that issued it — it is returned to the
 * admin once and is never derivable again from anything we store.
 */
final readonly class IssuedCredential
{
    public function __construct(
        public ApiCredential $credential,
        public string $plainTextToken,
    ) {}
}
