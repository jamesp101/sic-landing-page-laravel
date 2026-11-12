<?php

use App\Http\Middleware\RequestPolicy;
use App\Support\RequestError;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\ValidatePostSize;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Everything runs in the `web` group (session auth, CSRF): there is no stateless API.
            foreach (['auth', 'admin', 'actions', 'storage'] as $file) {
                Route::middleware('web')->group(__DIR__."/../routes/{$file}.php");
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn (Request $request) => route('login', ['redirect' => $request->getRequestUri()]));
        $middleware->redirectUsersTo('/dashboard');
        $middleware->trustProxies(at: array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '')))) ?: null);
        // Request policy (security headers, CSP, cache, noindex, staff hint): App\Http\Middleware\RequestPolicy.
        $middleware->web(append: [RequestPolicy::class]);
        // Before the route's `auth`: a signed-out request is answered 413/401/redirect by the policy first (large bodies are never read).
        $middleware->prependToPriorityList(AuthenticatesRequests::class, RequestPolicy::class);
        $middleware->replace(ValidatePostSize::class, App\Http\Middleware\ValidatePostSize::class);
        // Cookies the browser scripts read or write themselves (staff hint, Admin scope, sidebar state).
        $middleware->encryptCookies(except: ['sic_staff', 'admin_scope', 'sidebar_state']);
        // Taken as typed: passwords, and Rich content (Legacy Markdown keeps leading indentation and trailing newlines).
        $middleware->trimStrings(except: [
            'password', 'currentPassword', 'passwordConfirmation', 'password_confirmation', 'current_password',
            'content', 'heroContent', 'welcomeHeroContent', 'overlay',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $isAction = fn (Request $request) => $request->is('_actions/*');
        $isApi = fn (Request $request) => $request->is('api/*');

        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $isAction($request) || $isApi($request) || $request->expectsJson());
        // An expected rejection (403, 404, 409, …) is an answer, not an application error: keep it out of the log.
        $exceptions->dontReport(RequestError::class);

        $actionError = fn (string $type, string $code, string $message, int $status, array $extra = []) => response()->json(
            ['error' => ['type' => $type, 'code' => $code, 'message' => $message] + $extra],
            $status,
        );

        $exceptions->render(function (RequestError $error, Request $request) use ($isAction, $isApi, $actionError) {
            if ($isAction($request)) {
                return $actionError('ActionError', $error->errorCode, $error->getMessage(), $error->status());
            }
            if ($isApi($request) || $request->expectsJson()) {
                return response()->json(['message' => $error->getMessage()], $error->status());
            }
            if ($error->errorCode === RequestError::UNAUTHORIZED) {
                return redirect()->guest(route('login', ['redirect' => $request->getRequestUri()]));
            }
            abort($error->status(), $error->getMessage());
        });

        $exceptions->render(function (ValidationException $error, Request $request) use ($isAction, $actionError) {
            if ($isAction($request)) {
                return $actionError('ActionInputError', RequestError::BAD_REQUEST, 'Failed to validate.', 400, [
                    'fields' => $error->errors(),
                ]);
            }

            return null;
        });

        $exceptions->render(function (AuthenticationException $error, Request $request) use ($isAction, $isApi, $actionError) {
            if ($isAction($request)) {
                return $actionError('ActionError', RequestError::UNAUTHORIZED, 'Sign in required.', 401);
            }
            if ($isApi($request)) {
                return response()->json(['message' => 'Sign in required.'], 401);
            }

            return null;
        });
    })->create();
