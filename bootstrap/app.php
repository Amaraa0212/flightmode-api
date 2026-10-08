<?php

use App\Http\Middleware\EnsurePartnerAccess;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\RejectPartnerToken;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Private channels for the DCS app, authorised with the Sanctum Bearer token: POST /api/broadcasting/auth
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'partner' => EnsurePartnerAccess::class,
            'no-partner-token' => RejectPartnerToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API-only: always render exceptions as JSON
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => true);

        $exceptions->render(function (AuthenticationException $e) {
            return response()->json(['message' => 'Unauthenticated.', 'code' => 'unauthenticated'], 401);
        });

        $exceptions->render(function (NotFoundHttpException $e) {
            $message = $e->getPrevious() instanceof ModelNotFoundException ? 'Record not found.' : 'Not found.';

            return response()->json(['message' => $message, 'code' => 'not_found'], 404);
        });
    })->create();
