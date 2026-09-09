<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Symfony\Component\HttpFoundation\Response;

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
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

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
