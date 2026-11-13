<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * The one builder of an action error response: `{ error: { type, code, message, fields? } }`, the envelope
 * `resources/js/lib/actions.ts` turns into an `ActionError`. The request policy (signed-out visitor) and the
 * exception handler (rejection, validation failure, authentication failure) all answer through it.
 */
final class ActionErrorResponse
{
    public static function fromRequestError(RequestError $error): JsonResponse
    {
        return self::build('ActionError', $error->errorCode, $error->getMessage(), $error->status());
    }

    /** The answer to a signed-out visitor, whether the policy or an `auth` guard turned them away. */
    public static function unauthorized(): JsonResponse
    {
        return self::fromRequestError(RequestError::unauthorized());
    }

    public static function invalidInput(ValidationException $error): JsonResponse
    {
        return self::build('ActionInputError', RequestError::BAD_REQUEST, 'Failed to validate.', 400, $error->errors());
    }

    /** @param  array<string, list<string>>|null  $fields */
    private static function build(string $type, string $code, string $message, int $status, ?array $fields = null): JsonResponse
    {
        $error = ['type' => $type, 'code' => $code, 'message' => $message];
        if ($fields !== null) {
            $error['fields'] = $fields;
        }

        return new JsonResponse(['error' => $error], $status);
    }
}
