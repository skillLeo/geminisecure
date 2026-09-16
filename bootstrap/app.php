<?php

use App\Api\ApiError;
use App\Http\Middleware\Api\DeviceTime;
use App\Http\Middleware\Api\Idempotent;
use App\Http\Middleware\Api\ResolveDevice;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        /*
         * Sanctum ability gates for /api/v1.
         *
         * A device token is not a general key to the API. A guard's handset
         * may raise an alert; it may not read payroll. Laravel does not
         * register these aliases itself, so without this line a route asking
         * for 'ability:...' fails to resolve the middleware and — depending on
         * configuration — either errors or passes the request straight
         * through, which is the worse of the two.
         */
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,

            // The mobile API's own three (13 D1) — see each class.
            'device' => ResolveDevice::class,
            'device.time' => DeviceTime::class,
            'idempotent' => Idempotent::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * THE MOBILE API'S ONE ERROR SHAPE (13 D1):
         *
         *   { "error": { "code": "stable_snake_case", "message": "A sentence." } }
         *
         * A named refusal renders as itself. The framework's own refusals on
         * /api/* are given codes too, so an app branches on `error.code` and never
         * on a status code alone. Validation keeps Laravel's `errors` map beside
         * the envelope, because per-field messages are what a form needs.
         */
        $exceptions->render(fn (ApiError $error) => response()->json($error->body(), $error->status));

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return match (true) {
                $exception instanceof ValidationException => response()->json([
                    'error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()],
                    'message' => $exception->getMessage(),
                    'errors' => $exception->errors(),
                ], $exception->status),
                $exception instanceof AuthenticationException => response()->json(['error' => ['code' => 'unauthenticated', 'message' => 'This request needs a handset token.']], 401),
                // Sanctum's MissingAbilityException reaches here already converted.
                $exception instanceof AccessDeniedHttpException && $exception->getPrevious() instanceof MissingAbilityException,
                $exception instanceof MissingAbilityException => response()->json(['error' => ['code' => 'missing_ability', 'message' => 'This handset\'s token does not carry the ability this endpoint needs.']], 403),
                $exception instanceof AccessDeniedHttpException => response()->json(['error' => ['code' => 'forbidden', 'message' => $exception->getMessage()]], 403),
                $exception instanceof ThrottleRequestsException => response()->json(['error' => ['code' => 'rate_limited', 'message' => 'Too many requests from this handset. Wait and retry with the same Idempotency-Key.']], 429, $exception->getHeaders()),
                $exception instanceof ModelNotFoundException, $exception instanceof NotFoundHttpException => response()->json(['error' => ['code' => 'not_found', 'message' => 'Nothing at this address, or nothing this handset may see.']], 404),
                $exception instanceof MethodNotAllowedHttpException => response()->json(['error' => ['code' => 'method_not_allowed', 'message' => $exception->getMessage()]], 405),
                default => null,
            };
        });

        /*
         * Render errors as a real page rather than Laravel's bare status text.
         *
         * Rule 13: every state names what happened and offers the next action.
         * A stock "403 This action is unauthorized" does neither, and leaves
         * the reader with no way out except editing the URL — which for an
         * estate user bounced off a Gemini route means an unbreakable loop.
         *
         * Debug builds keep the stack trace: hiding a 500 behind friendly copy
         * while developing costs more than it saves.
         */
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return $response;
            }

            if (app()->hasDebugModeEnabled() && $response->getStatusCode() === 500) {
                return $response;
            }

            if (! in_array($response->getStatusCode(), [403, 404, 419, 429, 500, 503], true)) {
                return $response;
            }

            return inertia('ErrorPage', ['status' => $response->getStatusCode()])
                ->toResponse($request)
                ->setStatusCode($response->getStatusCode());
        });
    })->create();
