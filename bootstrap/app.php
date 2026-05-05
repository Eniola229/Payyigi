<?php

use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\LogRequestToAudit;
use App\Http\Middleware\RequireKyc;
use App\Http\Middleware\RequireNinVerified;
use App\Http\Middleware\RequireTransactionPin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {

        // ── Middleware aliases ────────────────────────────────────────────────
        $middleware->alias([
            'account.active'  => EnsureAccountActive::class,
            'txn.pin'         => RequireTransactionPin::class,
            'nin.verified'    => RequireNinVerified::class,
            'kyc'             => RequireKyc::class,
            'audit.log'       => LogRequestToAudit::class,
            'admin.active'    => \App\Http\Middleware\EnsureAdminIsActive::class,
            'role'            => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission'      => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);

        // ── Sanctum stateful domains ──────────────────────────────────────────
        $middleware->statefulApi();

        // ── API-wide throttling ───────────────────────────────────────────────
        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions) {

        // Return JSON for all API exceptions
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });

        $exceptions->render(function (\Illuminate\Auth\Access\AuthorizationException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
        });

        $exceptions->render(function (\Illuminate\Database\Eloquent\ModelNotFoundException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Resource not found.'], 404);
            }
        });

        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, Request $request) {
            return response()->json(['message' => 'Resource not found.'], 404);
        });

        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors'  => $e->errors(),
                ], 422);
            }
        });

        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Too many requests. Please slow down.'], 429);
            }
        });

    })->create();
