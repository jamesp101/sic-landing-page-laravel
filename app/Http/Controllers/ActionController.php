<?php

namespace App\Http\Controllers;

use App\Actions\ActionRegistry;
use App\Support\RequestError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Entry point of every action the Svelte islands call through `@/lib/actions`:
 * `POST /_actions/{module}.{action}` → `App\Actions\{Module}Actions::{action}()`.
 *
 * - Input: JSON or multipart form data, field names in camelCase (same names the islands send).
 * - Success: 200 `{ "data": <return value> }` (camelCase keys).
 * - Failure: bootstrap/app.php renders RequestError and ValidationException as
 *   `{ "error": { "type", "code", "message", "fields"? } }` with the matching status.
 * - Access: the action's `#[Action(Access::…)]` declaration is enforced (401/403) before the body runs, so before
 *   any input validation. A method without a declaration is not dispatchable (404).
 *
 * Action methods are resolved through the container, so they may type-hint Request and services.
 */
class ActionController extends Controller
{
    public function __invoke(Request $request, string $name): JsonResponse
    {
        $declared = ActionRegistry::find($name) ?? throw RequestError::notFound();
        [$class, $action, $access] = $declared;
        $access->enforce($request->user());

        return response()->json(['data' => app()->call([app($class), $action])]);
    }
}
