<?php

declare(strict_types=1);

namespace App\Domain\Mobile;

/**
 * Implemented by the two models that may hold a mobile token.
 *
 * WHY THIS EXISTS: a token outlives the login that produced it. On the web,
 * suspending a customer or deactivating a staff member takes effect on their
 * next request because the session guard re-reads the row (and, for merchant
 * users, an Authenticated-event listener logs them straight back out). A
 * bearer token has no such moment — without this check a deactivated
 * cashier keeps crediting sales for months, and the only remedy would be
 * remembering to hunt down their tokens by hand.
 *
 * So the account's own state is re-asked on EVERY authenticated mobile
 * request, by the model that owns that state, and a false answer is a 401
 * indistinguishable from a bad token.
 */
interface MobileTokenSubject
{
    /**
     * May this account use the mobile apps right now?
     *
     * Answers the account's standing only. It is not a permission check —
     * `merchant.can:` still gates what a staff member may do once inside.
     */
    public function mayUseMobileApp(): bool;
}
