<?php

namespace App\Http\Controllers;

use App\Http\RequestPolicyRules;
use App\Support\RequestError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use ReflectionMethod;

/**
 * Entry point of every action the Svelte islands call through `@/lib/actions`:
 * `POST /_actions/{module}.{action}` → `App\Actions\{Module}Actions::{action}()`.
 *
 * - Input: JSON or multipart form data, field names in camelCase (same names the islands send).
 * - Success: 200 `{ "data": <return value> }` (camelCase keys).
 * - Failure: bootstrap/app.php renders RequestError and ValidationException as
 *   `{ "error": { "type", "code", "message", "fields"? } }` with the matching status.
 * - Signed-out calls get 401 before the action runs, except `RequestPolicyRules::PUBLIC_ACTIONS`.
 *
 * Action methods are resolved through the container, so they may type-hint Request and services.
 */
class ActionController extends Controller
{
    public function __invoke(Request $request, string $name): JsonResponse
    {
        if (! preg_match('/^([a-z][A-Za-z]*)\.([a-z][A-Za-z]*)$/', $name, $match)) {
            throw RequestError::notFound();
        }
        if (! $request->user() && ! in_array($name, RequestPolicyRules::PUBLIC_ACTIONS, true)) {
            throw RequestError::unauthorized();
        }

        [, $module, $action] = $match;
        $class = 'App\\Actions\\'.ucfirst($module).'Actions';
        if (! class_exists($class) || ! method_exists($class, $action)) {
            throw RequestError::notFound();
        }
        $method = new ReflectionMethod($class, $action);
        if (! $method->isPublic() || $method->isStatic() || $method->getDeclaringClass()->getName() !== $class) {
            throw RequestError::notFound();
        }

        return response()->json(['data' => app()->call([app($class), $action])]);
    }
}
