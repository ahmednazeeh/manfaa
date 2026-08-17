<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\MobileError;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wraps the whole mobile tree and guarantees ONE error shape leaves it.
 *
 * Two different mechanisms produce an error on this platform and only one of
 * them is an exception:
 *
 *  - Controllers and the framework THROW, and the handler in bootstrap/app.php
 *    renders those through MobileError.
 *  - Middleware REFUSES by returning a response — EnsureMerchantPermission's
 *    `permission_required` is the live case, and it is shared with the panels
 *    so its shape cannot be changed for the apps' benefit.
 *
 * Without this, an app would need two error parsers and would meet the second
 * shape for the first time in production, on a permission refusal, at a till.
 *
 * Placed OUTERMOST on the group so it also sees the rendered form of anything
 * thrown deeper: Illuminate\Routing\Pipeline catches an exception at the pipe
 * that raised it and returns the rendered response back up the stack, so by
 * the time it reaches here it is an ordinary response and normalising is
 * enough.
 */
final class NormalisesMobileErrors
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() < 400) {
            return $response;
        }

        // Only JSON is ours to reshape. A binary or streamed error response
        // (a file route failing) is left exactly as it is.
        if (! $response instanceof JsonResponse) {
            return $response;
        }

        $payload = $response->getData(true);

        if (! is_array($payload)) {
            return $response;
        }

        $normalised = MobileError::fromPayload($payload, $response->getStatusCode());

        // Headers earned by the original refusal must survive — Retry-After
        // above all, which is the only thing telling a retrying client how
        // long to wait.
        foreach ($response->headers->allPreserveCase() as $header => $values) {
            if (! $normalised->headers->has($header)) {
                $normalised->headers->set($header, $values);
            }
        }

        return $normalised;
    }
}
