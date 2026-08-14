<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Base for the vendor-facing /v1 controllers. Every non-2xx response is the
 * published envelope {"error": {"code", "message", ...}} with a stable
 * machine code (docs/openapi.yaml, MachineCode) — vendors match on code,
 * never on message.
 */
abstract class V1Controller extends Controller
{
    /**
     * @param  array<string, mixed>|null  $errors  per-field messages (validation_failed only)
     * @param  array<string, mixed>|null  $meta  machine-readable context
     */
    protected function error(int $status, string $code, string $message, ?array $errors = null, ?array $meta = null): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];

        if ($errors !== null) {
            $error['errors'] = $errors;
        }

        if ($meta !== null) {
            $error['meta'] = $meta;
        }

        return new JsonResponse(['error' => $error], $status);
    }

    /**
     * Validates against $rules, converting the failure into the published
     * 422 validation_failed envelope.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    protected function validateEnvelope(Request $request, array $rules): array
    {
        try {
            return $request->validate($rules);
        } catch (ValidationException $exception) {
            throw new HttpResponseException($this->error(
                422,
                'validation_failed',
                'The given data was invalid.',
                errors: $exception->errors(),
            ));
        }
    }
}
