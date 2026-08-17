<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use App\Models\Merchant;
use App\Models\MerchantUser;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * §9.2 idempotency for /v1 writes. Keys are scoped to the authenticated
 * merchant. The decision table:
 *
 *   - no Idempotency-Key header        → 422 idempotency_key_required
 *   - same key, same request hash      → replay the stored response:
 *                                        HTTP 200 + Idempotency-Replay: true,
 *                                        body byte-identical to the original
 *   - same key, different request hash → 422 idempotency_key_reuse_mismatch
 *   - fresh key                        → handle, then store the response
 *
 * Race safety: the winner of two concurrent same-key requests is whoever
 * lands the unique (merchant_id, key) insert; the loser's insert raises a
 * unique violation and it falls into the replay path, polling briefly for
 * the winner's stored response. Only 2xx responses are stored — a failed
 * attempt releases the key so an honest retry with the same key can run
 * again (per the published retry rule, 5xx/network failures retry with the
 * SAME key; documented 4xx are terminal).
 *
 * Crash safety: the handler and the response persistence run inside ONE DB
 * transaction, so the business side effect (the recorded sale) and the
 * stored replay body commit or vanish together — a worker dying mid-request
 * can never leave a committed sale behind a key that has no stored
 * response. What such a crash CAN leave is the claimed key row itself
 * (inserted first, in its own commit, because the unique index is the
 * concurrency authority). A NULL-response row older than STALE_AFTER_SECONDS
 * is therefore an abandoned claim whose side effect is guaranteed not to
 * have committed: a retry deletes it and processes the request afresh
 * instead of answering 409 in_flight forever. Even a mis-judged takeover
 * (pathologically slow winner still running) cannot double-book a sale —
 * the transactions (merchant_id, invoice_no) unique index remains the
 * final authority.
 */
final class IdempotencyMiddleware
{
    /** Poll attempts × interval a loser waits for the winner's response. */
    private const int REPLAY_POLL_ATTEMPTS = 20;

    private const int REPLAY_POLL_MICROSECONDS = 100_000;

    /**
     * A NULL-response key row older than this is an abandoned claim (worker
     * died before its atomic handler+response commit): the next retry takes
     * it over. Far above any real request duration.
     */
    private const int STALE_AFTER_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        // The KEY IS SCOPED TO THE MERCHANT, whichever principal presented
        // it — a POS vendor token authenticates as the Merchant itself, a
        // till app as one of its staff. Scoping to the store rather than to
        // the account is deliberate: two tills in one shop retrying the same
        // key are retrying the SAME sale, and they must collide rather than
        // book it twice.
        $principal = $request->user();

        $merchant = match (true) {
            $principal instanceof Merchant => $principal,
            $principal instanceof MerchantUser => $principal->merchant,
            default => null,
        };

        if (! $merchant instanceof Merchant) {
            return $this->error(401, 'unauthorized', 'Unauthenticated.');
        }

        $key = trim((string) $request->header('Idempotency-Key', ''));

        if ($key === '') {
            return $this->error(422, 'idempotency_key_required', 'The Idempotency-Key header is required on every write.');
        }

        if (strlen($key) > 255) {
            return $this->error(422, 'validation_failed', 'The Idempotency-Key header must not exceed 255 characters.');
        }

        $hash = hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());

        $existing = $this->find($merchant, $key);

        if ($existing !== null && $this->abandoned($existing)) {
            // Crash leftover: the claim committed but the atomic
            // handler+response transaction did not, so no side effect
            // exists. Release the claim and process this retry afresh.
            $existing->delete();
            $existing = null;
        }

        if ($existing !== null) {
            return $this->resolveExisting($existing, $hash);
        }

        try {
            $record = IdempotencyKey::query()->create([
                'merchant_id' => $merchant->id,
                'key' => $key,
                'request_hash' => $hash,
                'created_at' => CarbonImmutable::now('UTC'),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Lost the insert race — the concurrent winner is processing
            // this key right now. Behave exactly like a later replay.
            return $this->resolveExisting($this->find($merchant, $key), $hash);
        }

        try {
            // One transaction around the handler AND the response store: the
            // recorded sale and its replay body are indivisible. The
            // handler's own DB::transaction nests as a savepoint, so its
            // "commit" only becomes real together with the response row.
            $response = DB::transaction(function () use ($next, $request, $record): Response {
                $response = $next($request);

                if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                    $record->forceFill([
                        'response_status' => $response->getStatusCode(),
                        'response_body' => json_decode((string) $response->getContent(), true),
                    ])->save();
                }

                return $response;
            });
        } catch (Throwable $exception) {
            $record->delete();

            throw $exception;
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            $record->delete();
        }

        return $response;
    }

    private function abandoned(IdempotencyKey $record): bool
    {
        return $record->response_status === null
            && $record->created_at !== null
            && $record->created_at->addSeconds(self::STALE_AFTER_SECONDS)->isBefore(CarbonImmutable::now('UTC'));
    }

    private function find(Merchant $merchant, string $key): ?IdempotencyKey
    {
        return IdempotencyKey::query()
            ->where('merchant_id', $merchant->id)
            ->where('key', $key)
            ->first();
    }

    private function resolveExisting(?IdempotencyKey $record, string $hash): Response
    {
        if ($record !== null && $record->request_hash !== $hash) {
            return $this->error(
                422,
                'idempotency_key_reuse_mismatch',
                'This Idempotency-Key was already used with a different request body.',
            );
        }

        for ($attempt = 0; $attempt < self::REPLAY_POLL_ATTEMPTS; $attempt++) {
            if ($record === null) {
                // The winner failed and released the key. Its error was not
                // stored (only 2xx responses are); the caller retries.
                break;
            }

            if ($record->response_status !== null) {
                return new JsonResponse($record->response_body, 200, ['Idempotency-Replay' => 'true']);
            }

            usleep(self::REPLAY_POLL_MICROSECONDS);
            $record = $record->fresh();
        }

        // Still in flight (or the winner failed) — not listed in the machine
        // code registry, therefore retryable with the same key per contract.
        return $this->error(409, 'idempotency_key_in_flight', 'This Idempotency-Key is being processed by a concurrent request; retry with the same key.');
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function error(int $status, string $code, string $message, array $extra = []): JsonResponse
    {
        return new JsonResponse([
            'error' => ['code' => $code, 'message' => $message, ...$extra],
        ], $status);
    }
}
